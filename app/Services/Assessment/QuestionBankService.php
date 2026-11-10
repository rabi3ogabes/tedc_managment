<?php

namespace App\Services\Assessment;

use App\Models\BankCategory;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Question banks: creating and validating questions, versioning, duplicate detection, bulk changes, spreadsheet import and export. */
class QuestionBankService
{
    private const MATERIAL = ['type', 'stem_ar', 'stem_en', 'payload', 'points', 'media'];

    /** @return array{question: Question, duplicate_of: ?string} */
    public function create(QuestionBank $bank, array $data, User $by): array
    {
        $data = $this->prepare($data);
        $hash = $this->hash($data);
        $duplicate = Question::where('bank_id', $bank->id)->where('status', 'active')->where('content_hash', $hash)->value('id');
        $q = Question::create($data + ['bank_id' => $bank->id, 'author_id' => $by->id, 'version' => 1, 'status' => $data['status'] ?? 'active', 'content_hash' => $hash]);

        return ['question' => $q, 'duplicate_of' => $duplicate];
    }

    /** A material change to an active question makes a new version; attempts already taken keep the version they saw. */
    public function update(Question $q, array $data, User $by): Question
    {
        $merged = array_replace($q->only(['type', 'stem_ar', 'stem_en', 'payload', 'points', 'media', 'difficulty', 'category_id', 'skill_ids', 'explanation_ar', 'explanation_en', 'tags']), $data);
        $merged = $this->prepare($merged);
        $material = collect(self::MATERIAL)->contains(fn ($k) => array_key_exists($k, $data) && $data[$k] != $q->{$k});

        if ($q->status !== 'active' || ! $material) {
            $q->update(array_diff_key($merged, ['type' => 1]) + ['content_hash' => $this->hash($merged)]);

            return $q->fresh();
        }

        return DB::transaction(function () use ($q, $merged, $by) {
            $q->update(['status' => 'retired']);

            return Question::create($merged + ['bank_id' => $q->bank_id, 'root_id' => $q->root_id ?? $q->id, 'version' => $q->version + 1, 'status' => 'active', 'author_id' => $by->id, 'content_hash' => $this->hash($merged)]);
        });
    }

    /** @param  list<string>  $ids */
    public function bulk(QuestionBank $bank, array $ids, array $changes): int
    {
        $set = array_filter(['category_id' => $changes['category_id'] ?? null, 'difficulty' => $changes['difficulty'] ?? null, 'status' => $changes['status'] ?? null], fn ($v) => $v !== null);
        $n = 0;
        foreach (Question::where('bank_id', $bank->id)->whereIn('id', $ids)->get() as $q) {
            $attrs = $set;
            if (isset($changes['tags'])) {
                $attrs['tags'] = array_values(array_unique(array_merge($q->tags ?? [], $changes['tags'])));
            }
            $q->update($attrs);
            $n++;
        }

        return $n;
    }

    /** @return array{created: int, errors: list<array{line: int, reason: string}>} */
    public function import(QuestionBank $bank, string $path, User $by): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($sheet) ?? []);
        $created = 0;
        $errors = [];
        foreach ($sheet as $i => $r) {
            $row = array_combine($header, array_pad(array_slice($r, 0, count($header)), count($header), null));
            if (trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }
            try {
                $data = $this->fromRow($row);
                $this->create($bank, $data, $by);
                $created++;
            } catch (ValidationException $e) {
                $errors[] = ['line' => $i + 2, 'reason' => collect($e->errors())->flatten()->first()];
            } catch (\Throwable $e) {
                $errors[] = ['line' => $i + 2, 'reason' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    public function export(QuestionBank $bank, string $format): string
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $cols = ['type', 'stem_ar', 'stem_en', 'difficulty', 'points', 'option_a', 'option_b', 'option_c', 'option_d', 'correct', 'accepted', 'value', 'tolerance', 'explanation_ar', 'payload_json'];
        $sheet->fromArray([$cols], null, 'A1');
        $row = 2;
        foreach (Question::where('bank_id', $bank->id)->where('status', 'active')->orderBy('created_at')->get() as $q) {
            $p = $q->payload;
            $opts = in_array($q->type, ['single_choice', 'multiple_select'], true) ? array_values($p['options']) : [];
            $correct = match ($q->type) {
                'single_choice', 'multiple_select' => collect($opts)->map(fn ($o, $i) => $o['correct'] ? chr(65 + $i) : null)->filter()->implode('|'),
                'true_false' => $p['correct'] ? 'true' : 'false',
                default => '',
            };
            $sheet->fromArray([[$q->type, $q->stem_ar, $q->stem_en, $q->difficulty, $q->points, $opts[0]['text_ar'] ?? '', $opts[1]['text_ar'] ?? '', $opts[2]['text_ar'] ?? '', $opts[3]['text_ar'] ?? '', $correct, $q->type === 'short_answer' ? implode('|', $p['accepted']) : '', $q->type === 'numeric' ? $p['value'] : '', $q->type === 'numeric' ? $p['tolerance'] : '', $q->explanation_ar, in_array($q->type, ['single_choice', 'multiple_select', 'true_false', 'short_answer', 'numeric'], true) ? '' : json_encode($p, JSON_UNESCAPED_UNICODE)]], null, 'A'.$row++);
        }
        $path = tempnam(sys_get_temp_dir(), 'qb');
        ($format === 'csv' ? new Csv($sheet->getParent()) : new Xlsx($sheet->getParent()))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** Whole tree of a bank with how many questions each node holds. @return Collection<int, BankCategory> */
    public function categories(QuestionBank $bank): Collection
    {
        $counts = Question::where('bank_id', $bank->id)->where('status', 'active')->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        return BankCategory::where('bank_id', $bank->id)->orderBy('sort_order')->orderBy('name_en')->get()->each(fn ($c) => $c->setAttribute('questions_count', (int) ($counts[$c->id] ?? 0)));
    }

    private function prepare(array $data): array
    {
        if (! QuestionTypes::has($data['type'] ?? '')) {
            throw ValidationException::withMessages(['type' => __('messages.assessment.unknown_type')]);
        }
        $data['payload'] = QuestionTypes::get($data['type'])->validate($data['payload'] ?? []);
        $data['points'] = (float) ($data['points'] ?? 1);
        $data['difficulty'] = in_array($data['difficulty'] ?? 'medium', ['easy', 'medium', 'hard'], true) ? ($data['difficulty'] ?? 'medium') : 'medium';

        return array_intersect_key($data, array_flip(['type', 'stem_ar', 'stem_en', 'media', 'payload', 'points', 'difficulty', 'skill_ids', 'explanation_ar', 'explanation_en', 'tags', 'category_id', 'status']));
    }

    private function hash(array $data): string
    {
        return sha1($data['type'].'|'.ArabicText::normalize((string) $data['stem_ar']).'|'.json_encode(QuestionTypes::get($data['type'])->correctAnswer($data['payload'])));
    }

    /** @param  array<string, mixed>  $r */
    private function fromRow(array $r): array
    {
        $type = trim((string) ($r['type'] ?? ''));
        $base = ['type' => $type, 'stem_ar' => trim((string) ($r['stem_ar'] ?? '')), 'stem_en' => ($r['stem_en'] ?? null) ?: null, 'difficulty' => strtolower(trim((string) ($r['difficulty'] ?? 'medium'))) ?: 'medium', 'points' => is_numeric($r['points'] ?? null) ? (float) $r['points'] : 1, 'explanation_ar' => ($r['explanation_ar'] ?? null) ?: null];
        if ($base['stem_ar'] === '') {
            throw new \RuntimeException('stem_ar is required');
        }
        if (! QuestionTypes::has($type)) {
            throw new \RuntimeException("unknown type {$type}");
        }
        if (! empty($r['payload_json'])) {
            return $base + ['payload' => json_decode((string) $r['payload_json'], true) ?: throw new \RuntimeException('payload_json is not valid JSON')];
        }
        $payload = match ($type) {
            'single_choice', 'multiple_select' => $this->choicePayload($r),
            'true_false' => ['correct' => in_array(strtolower(trim((string) ($r['correct'] ?? ''))), ['true', '1', 'yes', 'صح'], true)],
            'short_answer' => ['accepted' => array_filter(array_map('trim', explode('|', (string) ($r['accepted'] ?? ''))))],
            'numeric' => ['value' => $r['value'] ?? null, 'tolerance' => $r['tolerance'] ?? 0],
            default => throw new \RuntimeException("{$type} needs payload_json in a spreadsheet"),
        };

        return $base + ['payload' => $payload];
    }

    private function choicePayload(array $r): array
    {
        $letters = array_filter(array_map(fn ($l) => strtoupper(trim($l)), explode('|', (string) ($r['correct'] ?? ''))));
        $options = [];
        foreach (['a', 'b', 'c', 'd'] as $i => $l) {
            $text = trim((string) ($r['option_'.$l] ?? ''));
            if ($text !== '') {
                $options[] = ['id' => $l, 'text_ar' => $text, 'correct' => in_array(strtoupper($l), $letters, true)];
            }
        }

        return ['options' => $options];
    }
}
