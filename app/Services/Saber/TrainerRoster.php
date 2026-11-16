<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * «إدارة المدربين» (docs/saber/04-data-tools.md, D4): the trainers with their type, national ID, e-mail and mobile; an Excel template,
 * import (matched by national ID, then name, then e-mail — the national ID itself is never overwritten) and export; the approval that lets
 * supervisors pick a trainer; and filling e-mails and details from the staff records by national ID.
 */
class TrainerRoster
{
    /** The four SABER+ trainer types on Shaheen's trainer sources. */
    public const TYPES = ['center' => 'مدرب من المركز', 'ministry' => 'مدرب من الوزارة', 'school' => 'مدرب من منتسبي المدارس', 'external' => 'مدرب خارجي'];

    public const HEADERS = ['الاسم', 'رقم الهوية', 'البريد الإلكتروني', 'رقم الجوال', 'نوع المدرب'];

    public function row(Trainer $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name_ar ?: $t->name_en, 'national_id' => $t->national_id ? $this->mask($t->national_id) : null, 'has_national_id' => (bool) $t->national_id_hash,
            'email' => $t->email, 'phone' => $t->phone, 'type' => $t->source, 'status' => $t->status, 'validated' => (bool) $t->validated_at,
            'validated_at' => $t->validated_at?->toIso8601String(), 'employee_linked' => (bool) $t->employee_id,
        ];
    }

    /** @param array{name: string, national_id?: ?string, email?: ?string, phone?: ?string, type?: ?string} $d */
    public function save(?Trainer $trainer, array $d, User $actor): Trainer
    {
        $values = ['name_ar' => $d['name'], 'name_en' => $trainer?->name_en ?: $d['name'], 'email' => $d['email'] ?? $trainer?->email, 'phone' => $d['phone'] ?? $trainer?->phone, 'source' => $d['type'] ?? $trainer?->source ?? Trainer::CENTER];
        if (! empty($d['national_id'])) {
            $hash = Employee::nationalIdHash($d['national_id']);
            $other = Trainer::where('national_id_hash', $hash)->when($trainer, fn ($q) => $q->where('id', '!=', $trainer->id))->exists();
            if ($other) {
                throw new BusinessRuleException(__('messages.saber.trainers.duplicate_id'), 'duplicate_national_id');
            }
            $values += ['national_id' => preg_replace('/\D/', '', $d['national_id']), 'national_id_hash' => $hash];
        }
        if ($trainer) {
            $trainer->update($values);

            return $trainer;
        }
        $approved = $actor->hasPermission('trainers.validate');

        return Trainer::create($values + ['status' => 'active', 'validated_at' => $approved ? now() : null, 'validated_by' => $approved ? $actor->id : null]);
    }

    /** @return array{rows: list<array<string, mixed>>, errors: list<array{row: int, message: string}>} */
    public function previewImport(string $path): array
    {
        $sheet = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path)->getSheet(0);
        $data = $sheet->toArray(null, true, false, false);
        $headerAt = null;
        foreach (array_slice($data, 0, 10, true) as $i => $r) {
            if (collect($r)->contains(fn ($v) => in_array(ArabicMatch::normalize((string) $v), array_map([ArabicMatch::class, 'normalize'], ['الاسم', 'اسم المدرب', 'name']), true))) {
                $headerAt = $i;
                break;
            }
        }
        if ($headerAt === null) {
            throw new BusinessRuleException(__('messages.saber.trainers.no_header'), 'no_header');
        }
        $col = function (array $names) use ($data, $headerAt) {
            foreach ($data[$headerAt] as $i => $v) {
                if (in_array(ArabicMatch::normalize((string) $v), array_map([ArabicMatch::class, 'normalize'], $names), true)) {
                    return $i;
                }
            }

            return null;
        };
        $c = ['name' => $col(['الاسم', 'اسم المدرب', 'name']), 'id' => $col(['رقم الهوية', 'الرقم الشخصي', 'national_id', 'id']), 'email' => $col(['البريد الإلكتروني', 'البريد', 'email']),
            'phone' => $col(['رقم الجوال', 'الجوال', 'الهاتف', 'phone', 'mobile']), 'type' => $col(['نوع المدرب', 'النوع', 'type'])];
        $all = Trainer::get(['id', 'name_ar', 'name_en', 'email', 'national_id_hash']);
        $rows = [];
        $errors = [];
        foreach (array_slice($data, $headerAt + 1, null, true) as $i => $r) {
            $get = fn ($k) => $c[$k] !== null ? trim((string) ($r[$c[$k]] ?? '')) : '';
            $name = $get('name');
            if ($name === '') {
                continue;
            }
            $nid = preg_replace('/\D/', '', $get('id')) ?: null;
            $email = $get('email') ?: null;
            $phone = preg_replace('/[^\d+]/', '', $get('phone')) ?: null;
            if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $i + 1, 'message' => __('messages.saber.trainers.bad_email', ['v' => $email])];
                $email = null;
            }
            if ($phone && (strlen(ltrim($phone, '+')) < 8 || strlen(ltrim($phone, '+')) > 15)) {
                $errors[] = ['row' => $i + 1, 'message' => __('messages.saber.trainers.bad_phone', ['v' => $phone])];
                $phone = null;
            }
            $match = ($nid ? $all->firstWhere('national_id_hash', Employee::nationalIdHash($nid)) : null)
                ?? $all->first(fn ($t) => ArabicMatch::normalize($t->name_ar ?: $t->name_en) === ArabicMatch::normalize($name))
                ?? ($email ? $all->first(fn ($t) => $t->email && strcasecmp($t->email, $email) === 0) : null);
            $rows[] = ['row' => $i + 1, 'name' => $name, 'national_id' => $nid ? $this->mask($nid) : null, 'national_id_raw' => $nid, 'email' => $email, 'phone' => $phone,
                'type' => $this->type($get('type')), 'action' => $match ? 'update' : 'create', 'trainer_id' => $match?->id];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @param list<array<string, mixed>> $rows @return array{created: int, updated: int} */
    public function applyImport(array $rows, User $actor): array
    {
        $out = ['created' => 0, 'updated' => 0];
        DB::transaction(function () use ($rows, $actor, &$out) {
            foreach ($rows as $r) {
                $trainer = ! empty($r['trainer_id']) ? Trainer::find($r['trainer_id']) : null;
                // An existing trainer keeps their national ID; the file only adds one that was missing.
                $nid = $trainer?->national_id_hash ? null : ($r['national_id_raw'] ?? null);
                $this->save($trainer, ['name' => $r['name'], 'national_id' => $nid, 'email' => $r['email'] ?? null, 'phone' => $r['phone'] ?? null, 'type' => $r['type'] ?? null], $actor);
                $out[$trainer ? 'updated' : 'created']++;
            }
        });

        return $out;
    }

    /** Fills a trainer's e-mail, mobile and staff link from the staff record with the same national ID. @return array{matched: int, filled: int} */
    public function fillFromStaff(): array
    {
        $matched = 0;
        $filled = 0;
        Trainer::whereNotNull('national_id_hash')->chunkById(200, function (Collection $trainers) use (&$matched, &$filled) {
            $employees = Employee::with('user:id,email,phone')->whereIn('national_id_hash', $trainers->pluck('national_id_hash'))->get()->keyBy('national_id_hash');
            foreach ($trainers as $t) {
                $e = $employees[$t->national_id_hash] ?? null;
                if (! $e) {
                    continue;
                }
                $matched++;
                $patch = array_filter([
                    'email' => $t->email ? null : $e->user?->email, 'phone' => $t->phone ? null : $e->user?->phone,
                    'employee_id' => $t->employee_id ? null : $e->id, 'user_id' => $t->user_id ? null : $e->user_id, 'school_id' => $t->school_id ? null : $e->school_id,
                ]);
                if ($patch) {
                    $t->update($patch);
                    $filled++;
                }
            }
        });

        return ['matched' => $matched, 'filled' => $filled];
    }

    /** The trainers as a styled workbook (or the empty template with two example rows). */
    public function workbook(bool $template): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('المدربون')->setRightToLeft(true);
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $rows = $template
            ? [['محمد أحمد', '28763400000', 'mohammed@example.com', '55123456', self::TYPES['center']], ['سارة علي', '29163400000', 'sara@example.com', '66123456', self::TYPES['ministry']]]
            : Trainer::orderBy('name_ar')->get()->map(fn ($t) => [$t->name_ar ?: $t->name_en, $t->national_id, $t->email, $t->phone, self::TYPES[$t->source] ?? $t->sourceLabel('ar')])->all();
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2', true);
        }
        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '8A1538']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle('B2:B'.(count($rows) + 1))->getNumberFormat()->setFormatCode('@');
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $path = tempnam(sys_get_temp_dir(), 'trainers').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function type(string $label): string
    {
        $n = ArabicMatch::normalize($label);
        foreach (self::TYPES as $key => $text) {
            if ($n !== '' && (ArabicMatch::normalize($text) === $n || $n === $key)) {
                return $key;
            }
        }

        return match (true) {
            str_contains($n, 'وزاره') => 'ministry', str_contains($n, 'مدارس') || str_contains($n, 'مدرسه') => 'school', str_contains($n, 'خارجي') => 'external', default => 'center',
        };
    }

    private function mask(string $id): string
    {
        return strlen($id) > 4 ? str_repeat('•', strlen($id) - 4).substr($id, -4) : $id;
    }
}
