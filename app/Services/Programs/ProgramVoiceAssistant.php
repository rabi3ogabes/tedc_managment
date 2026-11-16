<?php

namespace App\Services\Programs;

use App\Ai\AiGuard;
use App\Models\JobTitle;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Ai\AiModelSettings;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\ProgramPlanner;
use Illuminate\Support\Str;

/**
 * A spoken (or typed) order such as "create a programme on classroom assessment for teachers with 3 to 5 years of
 * experience, female, with trainer Noora Al-Mohannadi, 12 hours, 25 trainees, in person" becomes a request the
 * administrator can read, correct and run. The deterministic parser always runs; when an AI model is allowed, it
 * refines the reading (a polished title, a summary, a better audience match) and the two are merged with the
 * database as the judge: job titles must exist, the trainer is matched locally, numbers are kept in range. The
 * request is then applied to a programme draft that the administrator verifies and saves — nothing is created here.
 */
class ProgramVoiceAssistant
{
    public const FEATURE = 'programs';

    public function __construct(private readonly ProgramRequestParser $parser, private readonly AiGuard $guard, private readonly AiModelSettings $models, private readonly ProgramPlanner $planner, private readonly SurveyAudience $audience) {}

    public function status(): array
    {
        return ['ai_available' => $this->guard->available(self::FEATURE, true), 'min_chars' => 3, 'max_chars' => 1000, 'examples' => $this->examples()];
    }

    /** @return array{transcript: string, language: string, engine: string, draft: array, understood: array, missing: list<string>, notes: list<array>, confidence: float, program: array} */
    public function parse(string $text, ?string $lang, User $by): array
    {
        $rules = $this->parser->parse($text, $lang);
        $lang = $rules['language'];
        $draft = $rules['draft'];
        $engine = 'rules';
        $notes = $rules['notes'];

        if ($this->guard->available(self::FEATURE, true)) {
            $ai = $this->refine($text, $lang, $by);
            if ($ai) {
                $draft = $this->merge($draft, $ai);
                $engine = 'ai+rules';
            } else {
                $notes[] = ['code' => 'ai_skipped', 'ar' => 'تعذّر الاستعانة بنموذج الذكاء الاصطناعي؛ قُرئ الطلب بالقواعد وحدها.', 'en' => 'The AI model could not be used; the request was read by the rules alone.'];
            }
        }
        $draft = $this->complete($draft, $lang);
        $missing = array_values(array_filter(['topic', 'audience'], fn ($k) => $k === 'topic' ? empty($draft['topic']) : empty($draft['job_title_ids'])));
        $confidence = $this->confidence($draft, $missing);

        return [
            'transcript' => trim($text), 'language' => $lang, 'engine' => $engine, 'draft' => $draft,
            'understood' => $this->understood($draft), 'missing' => $missing, 'notes' => $notes, 'confidence' => $confidence,
            'program' => $this->program($draft, $lang),
        ];
    }

    // ───────────────────────────── AI refinement

    private function refine(string $text, string $lang, User $by): ?array
    {
        $titles = JobTitle::orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en']);
        $list = $titles->map(fn ($t) => "{$t->code}: {$t->name_ar} / {$t->name_en}")->implode("\n");
        $system = $lang === 'en'
            ? 'You turn a spoken request to create a training programme for a government training centre (teachers and school staff) into structured fields. Read only what was said; never invent an audience, numbers or a trainer that were not mentioned. Use job title CODES from the list only. Title: a clean programme name without the words "create" or "programme" unless they are part of the topic. Summary: one sentence. Answer in English for the English fields and in Arabic for the Arabic ones.'
            : 'أنت تحوّل طلباً منطوقاً لإنشاء برنامج تدريبي في مركز تدريب حكومي (معلمون وكوادر مدرسية) إلى حقول منظمة. اقرأ ما قيل فقط؛ لا تخترع فئة أو أرقاماً أو مدرباً لم يُذكروا. استخدم رموز المسميات الوظيفية من القائمة فقط. العنوان: اسم برنامج نظيف بلا كلمات «أنشئ» أو «برنامج» إلا إن كانت جزءاً من الموضوع. النبذة: جملة واحدة. العربية الفصحى للحقول العربية والإنجليزية للحقول الإنجليزية.';
        $prompt = ($lang === 'en' ? 'Request: ' : 'الطلب: ')."\"{$text}\"\n\n".($lang === 'en' ? 'Job title codes:' : 'رموز المسميات الوظيفية:')."\n{$list}\n\n".($lang === 'en' ? 'Today: ' : 'اليوم: ').now()->toDateString();
        $schema = ['type' => 'object', 'required' => ['topic', 'title_ar', 'title_en', 'job_title_codes'], 'properties' => [
            'topic' => ['type' => 'string'], 'title_ar' => ['type' => 'string'], 'title_en' => ['type' => 'string'], 'summary_ar' => ['type' => 'string'], 'summary_en' => ['type' => 'string'],
            'job_title_codes' => ['type' => 'array', 'items' => ['type' => 'string']], 'genders' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['male', 'female']]],
            'experience_min' => ['type' => ['number', 'null']], 'experience_max' => ['type' => ['number', 'null']], 'trainer_name' => ['type' => ['string', 'null']], 'trainer_optional' => ['type' => 'boolean'],
            'total_hours' => ['type' => ['number', 'null']], 'capacity' => ['type' => ['integer', 'null']], 'delivery_mode' => ['type' => ['string', 'null'], 'enum' => ['in_person', 'online', 'hybrid', null]],
            'level' => ['type' => ['string', 'null'], 'enum' => ['beginner', 'intermediate', 'advanced', null]], 'start_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD when a date or a relative time was said'],
        ]];
        // Trainer names leave as placeholders and come back restored, so the model never sees a person's name.
        $names = Trainer::where('status', 'active')->get(['name_ar', 'name_en'])->flatMap(fn ($t) => [$t->name_ar, $t->name_en])->filter()->unique()->values()->all();
        $out = $this->guard->json(self::FEATURE, $system, $prompt, $schema, $by, true, $names);
        if (! is_array($out) || ! isset($out['topic'])) {
            return null;
        }
        $byCode = $titles->keyBy('code');
        $ids = collect((array) ($out['job_title_codes'] ?? []))->map(fn ($c) => $byCode->get(strtoupper(trim((string) $c)))?->id)->filter()->unique()->values()->all();
        $trainer = null;
        if (! empty($out['trainer_name'])) {
            $trainer = $this->matchTrainer((string) $out['trainer_name'], (bool) ($out['trainer_optional'] ?? false));
        }

        return [
            'topic' => ProgramRequestParser::limit((string) $out['topic'], 120), 'title_ar' => ProgramRequestParser::limit((string) ($out['title_ar'] ?? ''), 255), 'title_en' => ProgramRequestParser::limit((string) ($out['title_en'] ?? ''), 255),
            'summary_ar' => ProgramRequestParser::limit((string) ($out['summary_ar'] ?? ''), 500), 'summary_en' => ProgramRequestParser::limit((string) ($out['summary_en'] ?? ''), 500),
            'job_title_ids' => $ids, 'genders' => array_values(array_intersect((array) ($out['genders'] ?? []), ['male', 'female'])),
            'experience_min' => is_numeric($out['experience_min'] ?? null) ? (float) $out['experience_min'] : null, 'experience_max' => is_numeric($out['experience_max'] ?? null) ? (float) $out['experience_max'] : null,
            'trainer' => $trainer, 'total_hours' => is_numeric($out['total_hours'] ?? null) ? (float) $out['total_hours'] : null, 'capacity' => is_numeric($out['capacity'] ?? null) ? (int) $out['capacity'] : null,
            'delivery_mode' => in_array($out['delivery_mode'] ?? null, ['in_person', 'online', 'hybrid'], true) ? $out['delivery_mode'] : null,
            'level' => in_array($out['level'] ?? null, ['beginner', 'intermediate', 'advanced'], true) ? $out['level'] : null,
            'start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($out['start_date'] ?? '')) ? $out['start_date'] : null,
            'model' => $this->models->resolve('text')['model']['model'] ?? null,
        ];
    }

    private function matchTrainer(string $name, bool $optional): array
    {
        $best = null;
        foreach (Trainer::where('status', 'active')->get(['id', 'name_ar', 'name_en']) as $t) {
            foreach ([$t->name_ar, $t->name_en] as $candidate) {
                $score = $this->parser->nameScore($name, (string) $candidate);
                if ($score > ($best['score'] ?? 0)) {
                    $best = ['id' => $t->id, 'name' => $t->name_ar, 'name_en' => $t->name_en, 'score' => $score];
                }
            }
        }
        if ($best && $best['score'] >= ProgramRequestParser::TRAINER_MIN_SCORE) {
            return $best + ['query' => $name, 'optional' => $optional];
        }

        return ['id' => null, 'name' => null, 'name_en' => null, 'score' => $best['score'] ?? null, 'query' => $name, 'optional' => $optional];
    }

    /** The rules read is the base; the model fills what the rules missed and polishes the words; ids and numbers stay validated. */
    private function merge(array $rules, array $ai): array
    {
        $d = $rules;
        foreach (['topic', 'title_ar', 'title_en', 'summary_ar', 'summary_en'] as $k) {
            if (! empty($ai[$k])) {
                $d[$k] = $ai[$k];
            }
        }
        if (empty($d['job_title_ids']) && ! empty($ai['job_title_ids'])) {
            $d['job_title_ids'] = $ai['job_title_ids'];
        }
        foreach (['genders', 'experience_min', 'experience_max', 'total_hours', 'capacity', 'delivery_mode', 'level', 'start_date'] as $k) {
            if (($d[$k] ?? null) === null || $d[$k] === []) {
                $d[$k] = $ai[$k] ?? null;
            }
        }
        if (empty($d['trainer']['id']) && ! empty($ai['trainer'])) {
            $d['trainer'] = $ai['trainer'];
        }
        $d['model'] = $ai['model'] ?? null;

        return $d;
    }

    // ───────────────────────────── the readable request and the draft

    private function complete(array $d, string $lang): array
    {
        $d['job_titles'] = JobTitle::whereIn('id', $d['job_title_ids'] ?? [])->orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en'])->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name_ar' => $t->name_ar, 'name_en' => $t->name_en])->values()->all();
        $topic = $d['topic'] ?? null;
        if (empty($d['title_ar'])) {
            $d['title_ar'] = $topic ? (preg_match('/\p{Arabic}/u', $topic) ? (Str::startsWith($topic, ['برنامج', 'دوره', 'ورشه']) ? $topic : 'برنامج '.$topic) : 'برنامج '.$topic) : '';
        }
        if (empty($d['title_en'])) {
            $d['title_en'] = $topic && ! preg_match('/\p{Arabic}/u', $topic) ? Str::title($topic).' Programme' : '';
        }
        if (empty($d['summary_ar']) && $topic) {
            $aud = $d['job_titles'] ? implode('، ', array_column($d['job_titles'], 'name_ar')) : 'الفئة المستهدفة';
            $d['summary_ar'] = "برنامج تدريبي في {$topic} موجّه إلى {$aud}".$this->experienceText($d, 'ar').'.';
        }
        if (empty($d['summary_en']) && $topic && ! preg_match('/\p{Arabic}/u', $topic)) {
            $aud = $d['job_titles'] ? implode(', ', array_column($d['job_titles'], 'name_en')) : 'the target audience';
            $d['summary_en'] = "A training programme on {$topic} for {$aud}".$this->experienceText($d, 'en').'.';
        }
        $d['total_hours'] = isset($d['total_hours']) ? max(1, min(1000, (float) $d['total_hours'])) : null;
        $d['capacity'] = isset($d['capacity']) ? max(1, min(5000, (int) $d['capacity'])) : null;
        foreach (['experience_min', 'experience_max'] as $k) {
            $d[$k] = isset($d[$k]) ? max(0, min(60, (float) $d[$k])) : null;
        }

        return $d;
    }

    private function experienceText(array $d, string $lang): string
    {
        $min = $d['experience_min'] ?? null;
        $max = $d['experience_max'] ?? null;
        if ($min === null && $max === null) {
            return '';
        }
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');

        return $lang === 'ar'
            ? ($min !== null && $max !== null ? " بخبرة من {$fmt($min)} إلى {$fmt($max)} سنوات" : ($min !== null ? " بخبرة لا تقل عن {$fmt($min)} سنوات" : " بخبرة لا تزيد عن {$fmt($max)} سنوات"))
            : ($min !== null && $max !== null ? " with {$fmt($min)} to {$fmt($max)} years of experience" : ($min !== null ? " with at least {$fmt($min)} years of experience" : " with up to {$fmt($max)} years of experience"));
    }

    /** One clear sentence in each language saying exactly what will be created. */
    private function understood(array $d): array
    {
        $g = fn (string $lang) => match (($d['genders'] ?? []) === ['male'] ? 'm' : (($d['genders'] ?? []) === ['female'] ? 'f' : '')) {
            'm' => $lang === 'ar' ? 'الذكور' : 'male', 'f' => $lang === 'ar' ? 'الإناث' : 'female', default => '',
        };
        $audAr = $d['job_titles'] ? implode(' و', array_column($d['job_titles'], 'name_ar')) : 'فئة مستهدفة غير محددة';
        $audEn = $d['job_titles'] ? implode(' and ', array_column($d['job_titles'], 'name_en')) : 'an unspecified audience';
        $modeAr = ['in_person' => 'حضورياً', 'online' => 'عن بُعد', 'hybrid' => 'هجيناً'][$d['delivery_mode'] ?? ''] ?? null;
        $modeEn = ['in_person' => 'in person', 'online' => 'online', 'hybrid' => 'hybrid'][$d['delivery_mode'] ?? ''] ?? null;
        $levelAr = ['beginner' => 'للمبتدئين', 'intermediate' => 'بمستوى متوسط', 'advanced' => 'بمستوى متقدم'][$d['level'] ?? ''] ?? null;
        $levelEn = ['beginner' => 'beginner level', 'intermediate' => 'intermediate level', 'advanced' => 'advanced level'][$d['level'] ?? ''] ?? null;
        $tr = $d['trainer'] ?? null;
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');

        $ar = 'إنشاء برنامج «'.($d['title_ar'] ?: 'بلا اسم بعد').'» موجّه إلى '.$audAr.($g('ar') ? ' من '.$g('ar') : '').$this->experienceText($d, 'ar');
        $ar .= $tr ? ($tr['id'] ? '، مع المدرب '.$tr['name'].($tr['optional'] ? ' إن توفر' : '') : '، مدرب غير موجود في القائمة: «'.$tr['query'].'»') : '';
        $ar .= isset($d['total_hours']) ? '، '.$num($d['total_hours']).' ساعة' : '';
        $ar .= isset($d['capacity']) ? '، '.$d['capacity'].' متدرباً' : '';
        $ar .= $modeAr ? '، '.$modeAr : '';
        $ar .= $levelAr ? '، '.$levelAr : '';
        $ar .= ! empty($d['start_date']) ? '، يبدأ في '.$d['start_date'] : '';
        $en = 'Create the programme "'.($d['title_en'] ?: ($d['title_ar'] ?: 'untitled')).'" for '.$audEn.($g('en') ? ' ('.$g('en').')' : '').$this->experienceText($d, 'en');
        $en .= $tr ? ($tr['id'] ? ', with trainer '.($tr['name_en'] ?: $tr['name']).($tr['optional'] ? ' if available' : '') : ', trainer not in the list: "'.$tr['query'].'"') : '';
        $en .= isset($d['total_hours']) ? ', '.$num($d['total_hours']).' hours' : '';
        $en .= isset($d['capacity']) ? ', '.$d['capacity'].' trainees' : '';
        $en .= $modeEn ? ', '.$modeEn : '';
        $en .= $levelEn ? ', '.$levelEn : '';
        $en .= ! empty($d['start_date']) ? ', starting '.$d['start_date'] : '';

        return ['ar' => $ar.'.', 'en' => $en.'.'];
    }

    /** The programme form as the editor expects it (the person verifies and saves it). */
    private function program(array $d, string $lang): array
    {
        $audience = SurveyAudience::clean(array_filter(['job_title_ids' => $d['job_title_ids'] ?? [], 'genders' => $d['genders'] ?? [], 'experience_min' => $d['experience_min'] ?? null, 'experience_max' => $d['experience_max'] ?? null], fn ($v) => $v !== null && $v !== []));
        $tr = $d['trainer'] ?? null;

        return [
            'code' => $this->planner->nextCode(null), 'title_ar' => $d['title_ar'] ?? '', 'title_en' => $d['title_en'] ?? '', 'summary_ar' => $d['summary_ar'] ?? '', 'summary_en' => $d['summary_en'] ?? '',
            'delivery_mode' => $d['delivery_mode'] ?? 'in_person', 'level' => $d['level'] ?? 'intermediate', 'total_hours' => $d['total_hours'] ?? 12, 'capacity' => $d['capacity'] ?? 30, 'start_date' => $d['start_date'] ?? null,
            'trainers' => $tr && $tr['id'] ? [['id' => $tr['id'], 'role' => 'lead']] : [],
            'target_groups' => array_map(fn ($id) => ['job_title_id' => $id], $d['job_title_ids'] ?? []),
            'audience' => $audience, 'audience_summary' => $this->audience->describe($audience), 'english' => $lang === 'en' || ! empty($d['title_en']),
        ];
    }

    private function confidence(array $d, array $missing): float
    {
        $score = 0.0;
        $score += empty($d['topic']) ? 0 : 0.4;
        $score += empty($d['job_title_ids']) ? 0 : 0.3;
        $score += isset($d['experience_min']) || isset($d['experience_max']) ? 0.1 : 0;
        $score += ($d['trainer']['id'] ?? null) ? 0.1 * (float) ($d['trainer']['score'] ?? 1) : (isset($d['trainer']) ? 0 : 0.05);
        $score += isset($d['total_hours']) || isset($d['capacity']) ? 0.05 : 0;
        $score += ! empty($d['delivery_mode']) ? 0.05 : 0;

        return round(min(1, $score), 2);
    }

    private function examples(): array
    {
        return [
            ['ar' => 'أنشئ برنامج التقويم من أجل التعلم للمعلمين بخبرة من 3 إلى 5 سنوات، إناث، مع المدربة نورة المهندي إن توفرت، 12 ساعة، 25 متدربة، حضوري.', 'en' => 'Create a programme on assessment for learning for teachers with 3 to 5 years of experience, female, with trainer Noora Al-Mohannadi if available, 12 hours, 25 trainees, in person.'],
            ['ar' => 'برنامج القيادة المدرسية لمديري المدارس ونوابهم، خبرة أكثر من 10 سنوات، عن بعد، 20 مقعداً، الشهر القادم.', 'en' => 'A school leadership programme for principals and vice principals, more than 10 years of experience, online, 20 seats, next month.'],
        ];
    }
}
