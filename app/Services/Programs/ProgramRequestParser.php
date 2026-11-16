<?php

namespace App\Services\Programs;

use App\Models\JobTitle;
use App\Models\Trainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The deterministic reading of a spoken or typed request to create a programme, in Arabic or English:
 * "أنشئ برنامج التقويم من أجل التعلم للمعلمين بخبرة من 3 إلى 5 سنوات، إناث، مع المدربة نورة المهندي، 12 ساعة، 25 متدربة، حضوري".
 * It never guesses beyond the words: numbers come from digits or number words, the audience from the job titles in the
 * database (with their plural, feminine and definite forms), the trainer from the active trainers (fuzzy, with a score),
 * and everything it could not read is reported as missing so the person sees what to complete.
 */
class ProgramRequestParser
{
    public const TRAINER_MIN_SCORE = 0.72;

    private const NUMBER_WORDS_AR = [
        'صفر' => 0, 'واحد' => 1, 'واحده' => 1, 'اثنان' => 2, 'اثنين' => 2, 'اثنتين' => 2, 'اثنتان' => 2, 'ثلاث' => 3, 'ثلاثه' => 3, 'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5,
        'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7, 'ثمان' => 8, 'ثمانيه' => 8, 'ثماني' => 8, 'تسع' => 9, 'تسعه' => 9, 'عشر' => 10, 'عشره' => 10,
        'عشرون' => 20, 'عشرين' => 20, 'ثلاثون' => 30, 'ثلاثين' => 30, 'اربعون' => 40, 'اربعين' => 40, 'خمسون' => 50, 'خمسين' => 50, 'ستون' => 60, 'ستين' => 60,
        'مئه' => 100, 'مائه' => 100, 'مية' => 100,
    ];

    private const NUMBER_WORDS_EN = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
        'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'hundred' => 100,
    ];

    /** Spoken forms that name a job title's code or a whole category (leadership, teaching, support, administrative). */
    private const AUDIENCE_WORDS = [
        'TEACHER' => ['معلم', 'معلمين', 'معلمون', 'معلمات', 'معلمه', 'معلمي', 'مدرس', 'مدرسين', 'مدرسات', 'teacher', 'teachers'],
        'SENIOR_TEACHER' => ['معلم اول', 'معلمين اوائل', 'معلمي اوائل', 'معلمات اوائل', 'المعلم الاول', 'المعلمين الاوائل', 'senior teacher', 'senior teachers', 'lead teachers'],
        'PRINCIPAL' => ['مدير مدرسه', 'مديري المدارس', 'مديرو المدارس', 'مديرات المدارس', 'مدراء المدارس', 'مدراء', 'المديرين', 'مديرين', 'principal', 'principals', 'school principals', 'head teachers', 'headteachers'],
        'VICE_PRINCIPAL' => ['نائب مدير', 'نواب المدير', 'نواب المديرين', 'نواب مدراء', 'النواب', 'vice principal', 'vice principals', 'deputy principals', 'deputies'],
        'SUBJECT_COORD' => ['منسق ماده', 'منسقي المواد', 'منسقو المواد', 'منسقات المواد', 'المنسقين', 'منسقين', 'subject coordinator', 'subject coordinators', 'coordinators'],
        'ACADEMIC_ADVISOR' => ['مرشد اكاديمي', 'مرشدين اكاديميين', 'المرشدين', 'مرشدين', 'مرشدات', 'academic advisor', 'academic advisors', 'advisors', 'counsellors', 'counselors'],
        'SOCIAL_SPECIALIST' => ['اخصائي اجتماعي', 'اخصائيين اجتماعيين', 'الاخصائيين الاجتماعيين', 'اخصائيات اجتماعيات', 'social specialist', 'social specialists', 'social workers'],
        'PSYCH_SPECIALIST' => ['اخصائي نفسي', 'اخصائيين نفسيين', 'الاخصائيين النفسيين', 'اخصائيات نفسيات', 'psychological specialist', 'psychologists', 'psychological specialists'],
        'IT_SPECIALIST' => ['اخصائي تقنيه', 'اخصائيي تقنية المعلومات', 'تقنيه المعلومات', 'it specialist', 'it specialists', 'it staff'],
        'LEARNING_RESOURCES' => ['مصادر التعلم', 'اخصائي مصادر', 'learning resources', 'librarians'],
        'ADMIN_OFFICER' => ['موظف اداري', 'الموظفين الاداريين', 'موظفين اداريين', 'الاداريين', 'اداريين', 'administrative officer', 'administrative staff', 'admin staff', 'admin officers'],
        '@leadership' => ['قاده المدارس', 'القيادات المدرسيه', 'القاده', 'قاده', 'القيادات', 'قيادات', 'school leaders', 'school leadership', 'leaders', 'leadership'],
        '@teaching' => ['الهيئه التدريسيه', 'الهيئة التعليمية', 'teaching staff', 'educators'],
        '@support' => ['الاخصائيين', 'اخصائيين', 'specialists', 'support staff'],
    ];

    /** Where the audience, the experience, the trainer and the figures start inside the sentence (normalised text). */
    private const ANCHORS = '(?:\bلل?فئه المستهدفه\b|\bالفئه المستهدفه\b|\bموجه(?:ه)? (?:الي|ل)|\bمستهدف(?:ا|ه)?\b|\bلل[\p{Arabic}]|\bل(?:معلم|قاد|مدير|مدراء|نواب|منسق|مرشد|اخصائ|موظف|كل|جميع)|\bخبر[هة]\b|\bبخبر[هة]\b|\bذوي\b|\bمع المدرب|\bمع المدربه|\bالمدرب[هة]? |\bبإشراف|\bباشراف|\bيقدم|\bتقدم|\bلمد[هة]\b|\bبمد[هة]\b|\bعدد\b|\bبسع[هة]\b|\bسع[هة]\b|\bبواقع\b|\bحضوري|\bعن بعد|\bاونلاين|\bهجين|\bللذكور|\bللاناث|\bذكور|\bاناث|\bنساء|\bرجال|\bمستوي\b|\bللمبتدئين|\bللمتقدمين|\bيبدا\b|\bتبدا\b|\bابتداء\b|\bفي الاسبوع|\bفي الشهر|\bالاسبوع القادم|\bالشهر القادم|\b\d+ *(?:ساع|متدرب|مشارك|مقعد)|\bfor\b|\btargeting\b|\btarget(?:ed)? (?:at|audience)\b|\baimed at\b|\bwith (?:an? )?experience\b|\bexperience\b|\bwith trainer\b|\btrainer\b|\btrained by\b|\bdelivered by\b|\bby\b|\bwith\b|\bduration\b|\blasting\b|\bcapacity\b|\bonline\b|\bremote\b|\bin[ -]person\b|\bhybrid\b|\bmale\b|\bfemale\b|\bmen\b|\bwomen\b|\blevel\b|\bstarting\b|\bnext (?:week|month)\b|\b\d+ *(?:hours?|hrs?|participants?|trainees?|seats?|people))';

    private string $raw = '';

    public function parse(string $text, ?string $lang = null): array
    {
        $raw = $this->raw = trim($text);
        $lang = $lang ?: (preg_match('/\p{Arabic}/u', $raw) ? 'ar' : 'en');
        $n = $this->numbers($this->normalise($raw));
        $titles = JobTitle::get(['id', 'code', 'name_ar', 'name_en', 'category']);
        $trainers = Trainer::where('status', 'active')->get(['id', 'name_ar', 'name_en']);

        $topic = $this->original($this->topic($n), $raw);
        [$jobIds, $audienceHits] = $this->audience($n, $titles);
        $genders = $this->genders($n);
        [$expMin, $expMax] = $this->experience($n);
        $trainer = $this->trainer($n, $trainers);
        $hours = $this->figure($n, '(?:ساع(?:ه|ات)|hours?|hrs?)');
        $capacity = $this->figure($n, '(?:متدرب(?:ا|ين|ه|ات)?|مشارك(?:ا|ين|ه|ات)?|مقعد(?:ا)?|مقاعد|participants?|trainees?|seats?|people|persons?)');
        $days = $this->figure($n, '(?:يوم|ايام|days?)');
        $mode = $this->mode($n);
        $level = $this->level($n);
        $start = $this->startDate($n);

        $found = [];
        foreach (['topic' => $topic, 'audience' => $jobIds, 'experience' => $expMin ?? $expMax, 'genders' => $genders, 'trainer' => $trainer['id'] ?? null, 'hours' => $hours, 'capacity' => $capacity, 'mode' => $mode, 'level' => $level, 'start' => $start] as $k => $v) {
            if ($v !== null && $v !== [] && $v !== '') {
                $found[] = $k;
            }
        }
        $missing = array_values(array_diff(['topic', 'audience'], $found));
        $notes = [];
        if (($trainer['query'] ?? null) && ! ($trainer['id'] ?? null)) {
            $notes[] = ['code' => 'trainer_not_found', 'ar' => 'لم أجد مدرباً باسم «'.$trainer['query'].'» في قائمة المدربين النشطين.', 'en' => 'No active trainer named "'.$trainer['query'].'" was found.'];
        }
        if ($expMin !== null && $expMax !== null && $expMin > $expMax) {
            [$expMin, $expMax] = [$expMax, $expMin];
        }

        return [
            'language' => $lang, 'normalised' => $n,
            'draft' => [
                'topic' => $topic, 'job_title_ids' => $jobIds, 'audience_terms' => $audienceHits, 'genders' => $genders, 'experience_min' => $expMin, 'experience_max' => $expMax,
                'trainer' => $trainer, 'total_hours' => $hours ?? ($days ? $days * 5 : null), 'capacity' => $capacity, 'delivery_mode' => $mode, 'level' => $level, 'start_date' => $start,
            ],
            'found' => $found, 'missing' => $missing, 'notes' => $notes,
        ];
    }

    // ───────────────────────────── normalisation

    /** Arabic-Indic digits to ASCII, no tashkeel or tatweel, one alef and one yaa, taa marbuta as haa, Latin lower-cased, single spaces. */
    public function normalise(string $s): string
    {
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $s = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي', '،' => ',', '؛' => ';', '؟' => '?', '–' => '-', '—' => '-']);
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return trim($s);
    }

    /** Number words to digits, compounds first ("خمسه عشر" → 15, "twenty five" → 25). */
    private function numbers(string $s): string
    {
        $s = (string) preg_replace_callback('/\b(واحد|احدي|اثن[اي]|اثنت[اي]|ثلاث[هة]?|اربع[هة]?|خمس[هة]?|ست[هة]?|سبع[هة]?|ثمان(?:ي|يه)?|تسع[هة]?|احد) ?عشر[هة]?\b/u', function ($m) {
            $unit = ['واحد' => 1, 'احد' => 1, 'احدي' => 1, 'اثنا' => 2, 'اثني' => 2, 'اثنتا' => 2, 'اثنتي' => 2][$m[1]] ?? (self::NUMBER_WORDS_AR[$m[1]] ?? self::NUMBER_WORDS_AR[rtrim($m[1], 'هة')] ?? 0);

            return (string) (10 + $unit);
        }, $s);
        $s = (string) preg_replace_callback('/\b(عشرين|عشرون|ثلاثين|ثلاثون|اربعين|اربعون|خمسين|خمسون|ستين|ستون)\b(?: و ?(واحد|اثنين|ثلاث[هة]?|اربع[هة]?|خمس[هة]?|ست[هة]?|سبع[هة]?|ثمان(?:يه|ي)?|تسع[هة]?))?/u',
            fn ($m) => (string) (self::NUMBER_WORDS_AR[$m[1]] + (isset($m[2]) ? (self::NUMBER_WORDS_AR[$m[2]] ?? self::NUMBER_WORDS_AR[rtrim($m[2], 'هة')] ?? 0) : 0)), $s);
        $s = (string) preg_replace_callback('/\b(twenty|thirty|forty|fifty|sixty)[ -](one|two|three|four|five|six|seven|eight|nine)\b/u', fn ($m) => (string) (self::NUMBER_WORDS_EN[$m[1]] + self::NUMBER_WORDS_EN[$m[2]]), $s);
        foreach (self::NUMBER_WORDS_AR as $w => $v) {
            $s = (string) preg_replace('/(?<![\p{Arabic}])'.preg_quote($w, '/').'(?![\p{Arabic}])/u', (string) $v, $s);
        }
        foreach (self::NUMBER_WORDS_EN as $w => $v) {
            $s = (string) preg_replace('/\b'.$w.'\b/u', (string) $v, $s);
        }

        return $s;
    }

    // ───────────────────────────── the pieces

    private function topic(string $n): ?string
    {
        $lead = '(?:(?:انشئ|انشي|انشاء|اعمل|اضف|ابدا|اريد|ابغي|نريد|رجاء|من فضلك|please|create|make|add|start|set up|i want|i need|we need|new|a new|an?)\s+)*';
        $noun = '(?:برنامج(?:ا)?|دوره|ورشه|program(?:me)?|course|workshop)';
        $named = '(?:\s*(?:جديد(?:ه|ا)?|تدريبي(?:ه)?|new|training))*\s*(?:باسم|بعنوان|اسمه|اسمها|عنوانه|عنوانها|عن|في|حول|بشان|يسمي|called|named|titled|entitled|on|about|in)?\s*';
        if (! preg_match('/'.$lead.$noun.$named.'["«“]?(.+)$/u', $n, $m)) {
            return null;
        }
        $rest = trim($m[1]);
        // Cut at the first anchor; a bare "for" / "ل" only counts when an audience follows it, so "assessment for learning for teachers" keeps its name.
        $topic = $rest;
        if (preg_match_all('/'.self::ANCHORS.'/u', $rest, $all, PREG_OFFSET_CAPTURE)) {
            foreach ($all[0] as [$anchor, $offset]) {
                $weak = preg_match('/^(?:for|ل\p{Arabic}*|لل\p{Arabic}*)$/u', $anchor) && ! preg_match('/^ل(?:ل|معلم|قاد|مدير|مدراء|نواب|منسق|مرشد|اخصائ|موظف|كل|جميع)/u', $anchor);
                // preg offsets are bytes: slice with substr, and judge a weak anchor by the three words that follow it.
                $after = implode(' ', array_slice(preg_split('/\s+/u', trim(substr($rest, $offset + strlen($anchor)))) ?: [], 0, 2));
                if ($weak && ! $this->mentionsAudience(' '.$after)) {
                    continue;
                }
                $topic = substr($rest, 0, $offset);
                break;
            }
        }
        $topic = $this->clip($topic);
        $topic = (string) preg_replace('/\s+(?:موجه(?:ه)?|مستهدف(?:ا|ه)?|for|to)$/u', '', $topic);
        $topic = trim((string) preg_replace('/^(?:ال)?برنامج\s+/u', '', $topic));

        if ($topic === '' && preg_match('/(?:about|on|regarding|(?<![\p{L}])عن(?![\p{L}])|(?<![\p{L}])حول(?![\p{L}])|(?<![\p{L}])بشان(?![\p{L}]))\s+(.+)$/u', $rest, $m2)) {
            // "a programme for everyone about wellbeing": the subject comes after the audience.
            $cut = preg_split('/'.self::ANCHORS.'/u', $m2[1], 2);
            $topic = $this->clip((string) ($cut[0] ?? ''));
        }

        return $topic !== '' && mb_strlen($topic) <= 120 ? $topic : null;
    }

    /** Whether a fragment names an audience (a job title form, "everyone", "all staff"). */
    private function mentionsAudience(string $fragment): bool
    {
        if (preg_match('/(?:الجميع|جميع|كل الموظفين|كافه|everyone|all staff|all employees|the whole)/u', $fragment)) {
            return true;
        }
        foreach (self::AUDIENCE_WORDS as $words) {
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}])(?:ال|لل|ل|و|بال|كال|the )?'.preg_quote($this->normalise($w), '/').'(?![\p{L}])/u', $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: list<string>, 1: list<string>} job title ids and the words that matched */
    private function audience(string $n, Collection $titles): array
    {
        $byCode = $titles->keyBy('code');
        $ids = [];
        $hits = [];
        $add = function (string $code) use (&$ids, &$hits, $byCode, $titles) {
            if (str_starts_with($code, '@')) {
                foreach ($titles->where('category', substr($code, 1)) as $t) {
                    $ids[$t->id] = true;
                }
                $hits[] = $code;

                return;
            }
            if ($t = $byCode->get($code)) {
                $ids[$t->id] = true;
                $hits[] = $code;
            }
        };
        // Longest spoken forms first, so "معلم اول" wins over "معلم" and "نائب مدير" over "مدير".
        $forms = [];
        foreach (self::AUDIENCE_WORDS as $code => $words) {
            foreach ($words as $w) {
                $forms[] = [$this->normalise($w), $code];
            }
        }
        usort($forms, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $scan = $n;
        foreach ($forms as [$w, $code]) {
            $re = '/(?<![\p{L}])(?:ال|لل|ل|و|بال|كال)?'.preg_quote($w, '/').'(?![\p{L}])/u';
            if (preg_match($re, $scan)) {
                $add($code);
                $scan = (string) preg_replace($re, ' ', $scan);
            }
        }
        // Any job title by its own name (new titles the vocabulary does not know), on the stems of the words.
        foreach ($titles as $t) {
            if (isset($ids[$t->id])) {
                continue;
            }
            foreach ([$t->name_ar, $t->name_en] as $name) {
                $name = $this->normalise((string) $name);
                if ($name !== '' && mb_strlen($name) >= 4 && str_contains($this->stems($scan), $this->stems($name))) {
                    $ids[$t->id] = true;
                    $hits[] = $t->code;
                    break;
                }
            }
        }

        return [array_keys($ids), array_values(array_unique($hits))];
    }

    /** @return list<string> */
    private function genders(string $n): array
    {
        $g = [];
        if (preg_match('/(?<![\p{L}])(?:لل)?(?:ذكور|رجال|الذكور|الرجال)(?![\p{L}])|\b(?:male|males|men)\b/u', $n)) {
            $g[] = 'male';
        }
        if (preg_match('/(?<![\p{L}])(?:لل)?(?:اناث|نساء|الاناث|النساء|سيدات|معلمات|مديرات|مرشدات|اخصائيات|منسقات|متدربات)(?![\p{L}])|\b(?:female|females|women|ladies)\b/u', $n)) {
            $g[] = 'female';
        }
        if (count($g) === 2 || preg_match('/\b(?:الجنسين|للجنسين|both genders|all genders|mixed)\b/u', $n)) {
            return [];
        }

        return $g;
    }

    /** @return array{0: ?float, 1: ?float} */
    private function experience(string $n): array
    {
        $exp = '(?:خبر[هة]|بخبر[هة]|ذوي خبر[هة]|experience|experienced)';
        if (preg_match('/'.$exp.'\D{0,30}?(\d+(?:\.\d+)?)\s*(?:الي|إلى|و|-|to|and|through)\s*(\d+(?:\.\d+)?)/u', $n, $m) || preg_match('/(?:بين|من|between|from)\s*(\d+(?:\.\d+)?)\s*(?:الي|إلى|و|-|to|and)\s*(\d+(?:\.\d+)?)\s*(?:سن(?:ه|وات|ين)|years?|yrs?)/u', $n, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)\s*(?:الي|إلى|و|-|to|and|through)\s*(\d+(?:\.\d+)?)\s*(?:سن(?:ه|وات|ين)|years?|yrs?)/u', $n, $m)) {
            return [(float) $m[1], (float) $m[2]];
        }
        $min = null;
        $max = null;
        if (preg_match('/(?:اكثر من|فوق|لا تقل عن|على الاقل|علي الاقل|more than|over|at least|minimum(?: of)?)\s*(\d+(?:\.\d+)?)\s*(?:سن|years?|yrs?)/u', $n, $m) || preg_match('/(\d+(?:\.\d+)?)\s*\+\s*(?:سن|years?|yrs?)/u', $n, $m)) {
            $min = (float) $m[1];
        }
        if (preg_match('/(?:اقل من|لا تزيد عن|دون|تحت|less than|under|at most|maximum(?: of)?|up to)\s*(\d+(?:\.\d+)?)\s*(?:سن|years?|yrs?)/u', $n, $m)) {
            $max = (float) $m[1];
        }
        if ($min === null && $max === null && preg_match('/'.$exp.'\D{0,20}?(\d+(?:\.\d+)?)\s*(?:سن(?:ه|وات|ين)|years?|yrs?)/u', $n, $m)) {
            $min = (float) $m[1];
        }

        return [$min, $max];
    }

    /** @return array{id: ?string, name: ?string, query: ?string, score: ?float, optional: bool}|null */
    private function trainer(string $n, Collection $trainers): ?array
    {
        $optional = (bool) preg_match('/(?:ان وجد|ان توفر|اذا توفر|اذا كان متاح|ان كان متاح|if available|when available|if free|if possible)/u', $n);
        // Explicit cues first ("with trainer", "المدرب", "بإشراف"); the bare "with" / "مع" / "by" only when nothing else names a person.
        $honorific = '(?:الدكتور[هة]?|الاستاذ[هة]?|د\.?|أ\.?|dr\.?|mr\.?|ms\.?|mrs\.?|prof\.?)?';
        $name = '((?:[\p{L}\.\-]+\s?){1,4})';
        $strong = '/(?:مع المدرب[هة]?|المدرب[هة]?|بإشراف|باشراف|يقدمه[ا]?|تقدمه[ا]?|يدربه[ا]?|بتقديم|with trainer|trainer|trained by|delivered by|presented by|facilitated by|led by)\s+'.$honorific.'\s*'.$name.'/u';
        $weak = '/(?:(?<![\p{L}])مع(?![\p{L}])|\bwith|\bby)\s+'.$honorific.'\s*'.$name.'/u';
        if (! preg_match($strong, $n, $m) && ! preg_match($weak, $n, $m)) {
            return null;
        }
        $query = trim((string) preg_replace('/'.self::ANCHORS.'.*$/u', '', $m[1]));
        $query = $this->clip((string) preg_replace('/\s+(?:ان|اذا|if|when|and|و)\b.*$/u', '', $query));
        if ($query === '' || preg_match('/^(?:ان|اذا|if|an?|the)$/u', $query)) {
            return null;
        }
        $best = null;
        foreach ($trainers as $t) {
            foreach ([$t->name_ar, $t->name_en] as $name) {
                $score = $this->nameScore($query, (string) $name);
                if ($score > ($best['score'] ?? 0)) {
                    $best = ['id' => $t->id, 'name' => $t->name_ar, 'name_en' => $t->name_en, 'score' => $score];
                }
            }
        }
        if ($best && $best['score'] >= self::TRAINER_MIN_SCORE) {
            return $best + ['query' => $this->original($query, $this->raw) ?? $query, 'optional' => $optional];
        }

        return ['id' => null, 'name' => null, 'name_en' => null, 'score' => $best['score'] ?? null, 'query' => $this->original($query, $this->raw) ?? $query, 'optional' => $optional];
    }

    /** The fragment as the person wrote or said it: the normalised words located back in the original text (digits already unified), so hamza and taa marbuta survive. */
    private function original(?string $fragment, string $raw): ?string
    {
        if ($fragment === null || $fragment === '') {
            return $fragment;
        }
        $source = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $classes = ['ا' => '[اأإآ]', 'ه' => '[هة]', 'ي' => '[يى]', 'و' => '[وؤ]', 'ء' => '[ءئؤ]'];
        $re = '';
        foreach (preg_split('//u', $fragment, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $re .= $ch === ' ' ? '[\s,،]+' : (($classes[$ch] ?? preg_quote($ch, '/')).'[\x{064B}-\x{0652}\x{0670}\x{0640}]*');
        }

        return preg_match('/'.$re.'/iu', $source, $m) ? $this->clip($m[0]) : $fragment;
    }

    /** How well the spoken words match a trainer's name: honorifics dropped, the best window of the spoken words against the whole name, then the best single-word overlap. */
    public function nameScore(string $query, string $name): float
    {
        $clean = fn (string $s) => trim((string) preg_replace('/\b(?:د|أ|ا|dr|mr|ms|mrs|prof)\.?\s*/u', '', $this->normalise($s)));
        $q = preg_split('/\s+/u', $clean($query)) ?: [];
        $nm = $clean($name);
        if ($nm === '' || ! $q) {
            return 0.0;
        }
        $best = 0.0;
        for ($i = 0; $i < count($q); $i++) {
            for ($len = 1; $len <= min(4, count($q) - $i); $len++) {
                $window = implode(' ', array_slice($q, $i, $len));
                similar_text($window, $nm, $pct);
                $best = max($best, $pct / 100);
            }
        }
        $parts = array_filter(preg_split('/\s+/u', $nm) ?: [], fn ($p) => mb_strlen($p) >= 3);
        foreach ($q as $w) {
            foreach ($parts as $p) {
                similar_text($w, $p, $pct);
                if ($pct >= 85) {
                    $best = max($best, 0.75 + ($pct - 85) / 60);
                }
            }
        }

        return round(min(1, $best), 2);
    }

    private function figure(string $n, string $unit): ?float
    {
        if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)\s*'.$unit.'(?![\p{L}])/u', $n, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function mode(string $n): ?string
    {
        return match (true) {
            (bool) preg_match('/(?:هجين|مدمج|hybrid|blended)/u', $n) => 'hybrid',
            (bool) preg_match('/(?:عن بعد|اونلاين|افتراضي|الكتروني|online|remote|virtual)/u', $n) => 'online',
            (bool) preg_match('/(?:حضوري|وجاهي|in[ -]person|face to face|on[ -]?site)/u', $n) => 'in_person',
            default => null,
        };
    }

    private function level(string $n): ?string
    {
        return match (true) {
            (bool) preg_match('/(?:متقدم|للمتقدمين|advanced)/u', $n) => 'advanced',
            (bool) preg_match('/(?:مبتدئ|للمبتدئين|تمهيدي|تاسيسي|beginner|basic|introductory|foundation)/u', $n) => 'beginner',
            (bool) preg_match('/(?:متوسط|intermediate)/u', $n) => 'intermediate',
            default => null,
        };
    }

    private function startDate(string $n): ?string
    {
        $today = CarbonImmutable::today();
        if (preg_match('/(?:الاسبوع القادم|الاسبوع المقبل|next week)/u', $n)) {
            return $today->next(CarbonImmutable::SUNDAY)->toDateString();
        }
        if (preg_match('/(?:الشهر القادم|الشهر المقبل|next month)/u', $n)) {
            return $today->addMonthNoOverflow()->startOfMonth()->toDateString();
        }
        if (preg_match('/(?:بعد|in)\s*(\d+)\s*(?:يوم|ايام|days?)/u', $n, $m)) {
            return $today->addDays((int) $m[1])->toDateString();
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2})/u', $n, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Words reduced to stems for containment checks: definite articles and common plural / feminine endings dropped. */
    private function stems(string $s): string
    {
        return ' '.implode(' ', array_map(function (string $w) {
            $w = (string) preg_replace('/^(?:وال|بال|كال|لل|ال|و|ل)/u', '', $w);
            $w = (string) preg_replace('/(?:ين|ون|ات|ان|ه|ي|s)$/u', '', $w);

            return $w;
        }, preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [])).' ';
    }

    /** Quotes, dashes, commas and spaces stripped from both ends without touching the bytes of multibyte letters (trim() would). */
    private function clip(string $s): string
    {
        return (string) preg_replace('/^[\s"«»“”,،.;:\-]+|[\s"«»“”,،.;:\-]+$/u', '', $s);
    }

    public static function limit(?string $s, int $max): ?string
    {
        return $s === null ? null : Str::limit(trim($s), $max, '');
    }
}
