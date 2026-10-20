<?php

namespace App\Services\Import;

use App\Migration\Cleanser;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PdActivityType;
use App\Models\Role;
use App\Support\XlsxStream;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Ministry's trainee extract: one row per employee and course attended (employees of the government schools, with the courses
 * they took from 2008 on). It becomes
 *   - people: one employee and one user per national ID (a re-hired person keeps every employee number they have had),
 *   - the schools, their sub-units and the job titles the extract names (matched to what exists, created when new),
 *   - the training record: every course row as an approved external professional-development record, so it counts in the hours a
 *     trainee trained last year, in the annual-hours and professional-development ledgers and on the trainee's profile.
 *
 * Nothing is guessed silently and nothing is dropped silently: every source row is accounted for in the report (imported, already
 * there, or rejected with the reason), the hours imported are compared to the hours in the file, and a second run changes nothing
 * (people are matched by the national ID's blind index, records by a hash of their content). Streams the workbook — a few
 * megabytes of memory for 150 000 rows.
 */
class TraineeHistoryImport
{
    public const SOURCE = 'moe-training-records';

    public const TYPE_CODE = 'SEC_COURSE';

    /** Header → key. Every one must be in the file. */
    private const HEADERS = [
        'IDNO' => 'idno', 'EMPNO_SEQ' => 'seq', 'EMPNAME' => 'name', 'NATNAME' => 'nat', 'EMPNO' => 'empno', 'SECAPPMDT' => 'appointed', 'ORGNNAME' => 'org', 'SUBORGNAME' => 'sub',
        'EQLEVEL_DESC' => 'eq', 'CERT_NAME' => 'cert', 'SP_CODE_DESC' => 'spec', 'JOBNAME' => 'job', 'EMPJOBCLASS_DESC' => 'jobclass', 'EMPCLASS_DESC' => 'empclass', 'EMP_TYPE_DESC' => 'kind',
        'SEX_DESC' => 'sex', 'SEC_EMAIL' => 'email', 'MOBILE_NO' => 'mobile', 'COURSE_TYPE_NAME_A' => 'ctype', 'TRACK_DESCE' => 'track', 'COURSE_NAME_A' => 'course', 'COUNTRY_NAME_A' => 'country',
        'PROVIDER_NAME_A' => 'provider', 'COURSE_DT_FROM' => 'from', 'COURSE_DT_END' => 'to', 'DURATION' => 'hours', 'PROG_JOBNO' => 'jobno', 'PROG_JOBNO_DESC' => 'jobdesc',
    ];

    /** Columns that newer extracts carry and older ones do not: read when present, empty otherwise. */
    private const OPTIONAL_HEADERS = ['EMPTYPE' => 'emptype'];

    /** Organisation names in the extract that are not schools. */
    private const PSEUDO_ORGS = ['مجهزين للحذف', 'أجازة طويلة مدارس', 'لا يعملون_مدارس'];

    /** …and those of them whose people no longer work for the Ministry. */
    private const INACTIVE_ORGS = ['مجهزين للحذف', 'لا يعملون_مدارس'];

    /** Employment classes that mean the person has left. */
    private const ENDED = ['استقالة', 'عدم تجديد تعاقد', 'انهاء خدمات', 'بلوغ السن القانونية', 'إعفاء من العمل', 'مؤقتين انهاء عقد', 'إنتداب إلى جهات أخرى'];

    private const QUALIFICATION = ['جامعي' => 'bachelor', 'ماجستير' => 'master', 'دكتوراة' => 'phd', 'دكتوراه' => 'phd', 'دبلوم متوسط' => 'diploma', 'دبلوم دراسات عليا' => 'postgrad_diploma',
        'ثانوي' => 'secondary', 'اعدادي' => 'preparatory', 'ابتدائي' => 'primary', 'بدون مؤهل' => 'none'];

    /** Masculine nationality adjective (as the extract writes it, normalised) → the country name the platform's rules use. */
    private const NATIONALITY = ['قطري' => 'Qatar', 'مصري' => 'Egypt', 'اردني' => 'Jordan', 'سوري' => 'Syria', 'فلسطيني' => 'Palestine', 'سوداني' => 'Sudan', 'تونسي' => 'Tunisia', 'يمني' => 'Yemen',
        'سعودي' => 'Saudi Arabia', 'عراقي' => 'Iraq', 'لبناني' => 'Lebanon', 'مغربي' => 'Morocco', 'تركي' => 'Turkey', 'عماني' => 'Oman', 'ايراني' => 'Iran', 'باكستاني' => 'Pakistan', 'هندي' => 'India',
        'جزائري' => 'Algeria', 'كندي' => 'Canada', 'امريكي' => 'United States', 'بحريني' => 'Bahrain', 'بريطاني' => 'United Kingdom', 'موريتاني' => 'Mauritania', 'صومالي' => 'Somalia', 'كويتي' => 'Kuwait',
        'اماراتي' => 'United Arab Emirates', 'ليبي' => 'Libya', 'استرالي' => 'Australia', 'فلبيني' => 'Philippines', 'بنغالي' => 'Bangladesh', 'افغاني' => 'Afghanistan', 'اندونيسي' => 'Indonesia', 'نيجيري' => 'Nigeria',
        'جنوب افريقي' => 'South Africa', 'فرنسي' => 'France', 'الماني' => 'Germany', 'ايطالي' => 'Italy', 'اسباني' => 'Spain', 'روسي' => 'Russia', 'صيني' => 'China', 'ياباني' => 'Japan', 'سريلانكي' => 'Sri Lanka',
        'نيبالي' => 'Nepal', 'بنغلاديشي' => 'Bangladesh', 'برازيلي' => 'Brazil', 'قبرصي' => 'Cyprus', 'ايرلندي' => 'Ireland', 'اوكراني' => 'Ukraine', 'نيوزيلندي' => 'New Zealand', 'نيوزيلاندي' => 'New Zealand', 'هولندي' => 'Netherlands',
        'سويدي' => 'Sweden', 'نمساوي' => 'Austria', 'بلجيكي' => 'Belgium', 'البانى' => 'Albania', 'الباني' => 'Albania', 'بلغاري' => 'Bulgaria', 'روماني' => 'Romania', 'بولندي' => 'Poland', 'كرواتي' => 'Croatia',
        'مالي' => 'Mali', 'نيجري' => 'Niger', 'سنغالي' => 'Senegal', 'ساحل عاجي' => 'Ivory Coast', 'اوغندي' => 'Uganda', 'تانزاني' => 'Tanzania', 'مكسيكي' => 'Mexico', 'ليسوتي' => 'Lesotho', 'طاجاكستاني' => 'Tajikistan',
        'طاجيكستاني' => 'Tajikistan', 'افريقي جنوبي' => 'South Africa', 'بيلاروسي' => 'Belarus', 'بولنديه' => 'Poland', 'كيني' => 'Kenya', 'اثيوبي' => 'Ethiopia', 'اريتري' => 'Eritrea', 'تشادي' => 'Chad', 'جيبوتي' => 'Djibouti', 'قمري' => 'Comoros', 'ماليزي' => 'Malaysia'];

    /** @var array<string, int> */
    private array $stats = [];

    /** @var list<array{code: string, empno?: string, row?: int, file?: string, detail?: string}> */
    private array $issues = [];

    /** @var array<string, int> */
    private array $unmappedNationalities = [];

    /**
     * @param  list<string>  $files
     * @param  callable(string): void|null  $log
     * @return array<string, mixed> the report
     */
    public function run(array $files, bool $dry = false, ?callable $log = null, ?string $source = null): array
    {
        $log ??= fn (string $m) => null;
        $source ??= self::SOURCE;
        $this->stats = array_fill_keys(['source_rows', 'rejected_rows', 'people', 'people_created', 'people_updated', 'employee_numbers', 'schools_created', 'schools_matched', 'departments_created', 'job_titles_created', 'job_titles_matched',
            'activities_imported', 'activities_existing', 'activities_rejected', 'placeholder_emails', 'inactive_people', 'source_hours', 'imported_hours'], 0);
        $this->issues = [];
        $this->unmappedNationalities = [];
        foreach ($files as $f) {
            if (! is_file($f)) {
                throw new RuntimeException("File not found: {$f}");
            }
        }
        $started = microtime(true);

        // ── 1. everyone in the files: one record per employee number
        $records = [];
        $jobCodes = [];
        $jobClassVotes = [];
        foreach ($files as $fi => $file) {
            $log('Reading people from '.basename($file).' …');
            foreach ($this->rows($file) as $n => $r) {
                $this->stats['source_rows']++;
                $empno = $this->digits($r['empno']);
                $idno = $this->digits($r['idno']);
                if ($empno === '' || $idno === '' || Cleanser::name((string) $r['name']) === '') {
                    $this->reject('identity_missing', $empno ?: null, $n, $fi);

                    continue;
                }
                // An employee number filed under two national IDs: the second person keeps their own record under a compound key.
                $key = isset($records[$empno]) && $records[$empno]['idno'] !== $idno ? $empno.'#'.$idno : $empno;
                if ($key !== $empno && ! isset($records[$key])) {
                    $this->issues[] = ['code' => 'empno_filed_under_two_ids', 'empno' => $empno, 'detail' => 'each person kept; the second gets a suffixed employee number'];
                }
                if (! isset($records[$key])) {
                    $records[$key] = [
                        'empno' => $empno, 'idno' => $idno, 'seq' => is_numeric($r['seq']) ? (int) $r['seq'] : 0, 'name' => Cleanser::name((string) $r['name']), 'nat' => Cleanser::name((string) $r['nat']),
                        'appointed' => Cleanser::date($r['appointed']), 'org' => Cleanser::name((string) $r['org']), 'sub' => Cleanser::name((string) $r['sub']), 'eq' => Cleanser::name((string) $r['eq']),
                        'cert' => Cleanser::name((string) $r['cert']) ?: null, 'spec' => Cleanser::name((string) $r['spec']) ?: null, 'job' => Cleanser::name((string) $r['job']), 'jobclass' => Cleanser::name((string) $r['jobclass']),
                        'empclass' => Cleanser::name((string) $r['empclass']), 'kind' => Cleanser::name((string) $r['kind']), 'sex' => Cleanser::gender((string) $r['sex']), 'email' => Cleanser::email((string) $r['email']),
                        'phone' => Cleanser::phone((string) $r['mobile']), 'emptype' => trim((string) ($r['emptype'] ?? '')) ?: null,
                    ];
                    $k = Cleanser::arabic($records[$key]['job']);
                    $jobClassVotes[$k][$records[$key]['jobclass']] = ($jobClassVotes[$k][$records[$key]['jobclass']] ?? 0) + 1;
                }
                if ($r['jobdesc'] !== null && $r['jobno'] !== null) {
                    $jobCodes[Cleanser::arabic((string) $r['jobdesc'])] ??= $this->digits($r['jobno']);
                }
            }
        }
        $people = $this->people($records);
        $this->stats['people'] = count($people);
        $this->stats['employee_numbers'] = count($records);
        $log(sprintf('%d rows, %d employee numbers, %d people.', $this->stats['source_rows'], count($records), count($people)));

        // ── 2. what the people belong to
        $schools = $this->schools($people, $dry, $log);
        $departments = $this->departments($people, $schools, $dry);
        $jobTitles = $this->jobTitles($people, $jobCodes, $jobClassVotes, $dry);

        // ── 3. the people
        $idnoToEmployee = $this->writePeople($people, $schools, $departments, $jobTitles, $source, $dry, $log);

        // ── 4. the courses
        $typeId = $dry ? 'dry-run' : PdActivityType::firstOrCreate(['code' => self::TYPE_CODE], ['name_ar' => 'دورة تدريبية (سجل الوزارة)', 'name_en' => 'Course (Ministry record)',
            'hour_rules' => ['attendee' => ['factor' => 1]], 'evidence_required' => false, 'is_active' => true])->id;
        $this->writeActivities($files, $idnoToEmployee, $typeId, $source, $dry, $log);

        $report = ['dry_run' => $dry, 'source' => $source, 'files' => array_map('basename', $files), 'seconds' => round(microtime(true) - $started, 1)] + $this->stats
            + ['unmapped_nationalities' => count($this->unmappedNationalities), 'issues' => count($this->issues), 'issue_samples' => array_slice($this->issues, 0, 50)]
            + ['reconciled' => $this->stats['source_rows'] === $this->stats['rejected_rows'] + $this->stats['activities_imported'] + $this->stats['activities_existing'] + $this->stats['activities_rejected'],
                'hours_match' => abs($this->stats['source_hours'] - $this->stats['imported_hours']) < 0.01 || $this->stats['activities_existing'] > 0];
        if (! $dry) {
            AuditLog::create(['action' => 'trainee_history_imported', 'auditable_type' => Employee::class, 'auditable_id' => null, 'new_values' => array_diff_key($report, ['issue_samples' => 1])]);
        }

        return $report;
    }

    /** @return array<string, int> */
    public function unmappedNationalities(): array
    {
        arsort($this->unmappedNationalities);

        return $this->unmappedNationalities;
    }

    // ───────────────────────────── reading

    /** @return \Generator<int, array<string, mixed>> the rows of one file keyed by field name */
    private function rows(string $file): \Generator
    {
        $map = null;
        foreach (XlsxStream::rows($file) as $i => $cells) {
            if ($map === null) {
                $head = array_map(fn ($h) => strtoupper(trim((string) $h)), $cells);
                $map = [];
                foreach (self::HEADERS as $h => $key) {
                    $pos = array_search($h, $head, true);
                    if ($pos === false) {
                        throw new RuntimeException('The column '.$h.' is missing from '.basename($file).'.');
                    }
                    $map[$key] = $pos;
                }
                foreach (self::OPTIONAL_HEADERS as $h => $key) {
                    $pos = array_search($h, $head, true);
                    $map[$key] = $pos === false ? null : $pos;
                }

                continue;
            }
            $row = [];
            foreach ($map as $key => $pos) {
                $v = $pos === null ? null : ($cells[$pos] ?? null);
                $row[$key] = is_string($v) ? (trim($v) === '' ? null : $v) : $v;
            }
            yield $i + 1 => $row;
        }
    }

    private function digits(mixed $v): string
    {
        return preg_replace('/\D/', '', Cleanser::digits((string) ($v ?? ''))) ?? '';
    }

    private function reject(string $code, ?string $empno, int $row, int $file): void
    {
        $this->stats['rejected_rows']++;
        $this->issues[] = ['code' => $code, 'empno' => $empno, 'row' => $row, 'file' => (string) $file];
    }

    // ───────────────────────────── people

    /**
     * One person per national ID. The employment that counts is the best one: not ended, in a real school, latest appointment, highest
     * sequence. Gaps in it (no e-mail, no phone) are filled from the person's other employments.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function people(array $records): array
    {
        $byId = [];
        foreach ($records as $r) {
            $byId[$r['idno']][] = $r;
        }
        $out = [];
        foreach ($byId as $idno => $list) {
            usort($list, fn ($a, $b) => $this->rank($b) <=> $this->rank($a));
            $cur = $list[0];
            $pick = fn (string $k) => collect($list)->pluck($k)->first(fn ($v) => $v !== null && $v !== '');
            $real = collect($list)->first(fn ($r) => ! in_array($r['org'], self::PSEUDO_ORGS, true) && $r['org'] !== '');
            $first = collect($list)->pluck('appointed')->filter()->min();
            $inactive = in_array($cur['empclass'], self::ENDED, true) || in_array($cur['org'], self::INACTIVE_ORGS, true);
            $out[$idno] = array_merge($cur, [
                'records' => $list, 'school' => $real['org'] ?? null, 'email' => $pick('email'), 'phone' => $pick('phone'), 'eq' => $pick('eq'), 'cert' => $pick('cert'), 'spec' => $pick('spec'),
                'first_appointed' => $first, 'inactive' => $inactive,
            ]);
            if ($inactive) {
                $this->stats['inactive_people']++;
            }
        }

        return $out;
    }

    private function rank(array $r): string
    {
        $ended = in_array($r['empclass'], self::ENDED, true) || in_array($r['org'], self::INACTIVE_ORGS, true) ? '0' : '1';
        $real = in_array($r['org'], self::PSEUDO_ORGS, true) ? '0' : '1';

        return $ended.$real.($r['appointed'] ?? '0000-00-00').str_pad((string) $r['seq'], 3, '0', STR_PAD_LEFT);
    }

    // ───────────────────────────── schools, sub-units, job titles

    /** @param array<string, array<string, mixed>> $people @return array<string, string> normalised name => school id */
    private function schools(array $people, bool $dry, callable $log): array
    {
        $existing = [];
        foreach (DB::table('schools')->get(['id', 'name_ar']) as $s) {
            $existing[Cleanser::arabic($s->name_ar)] = $s->id;
        }
        $names = [];
        foreach ($people as $p) {
            foreach ($p['records'] as $r) {
                if ($r['org'] !== '' && ! in_array($r['org'], self::PSEUDO_ORGS, true)) {
                    $names[Cleanser::arabic($r['org'])] ??= $r['org'];
                }
            }
        }
        $map = [];
        $rows = [];
        foreach ($names as $norm => $name) {
            if (isset($existing[$norm])) {
                $map[$norm] = $existing[$norm];
                $this->stats['schools_matched']++;

                continue;
            }
            $id = (string) Str::uuid7();
            $map[$norm] = $id;
            $this->stats['schools_created']++;
            $rows[] = ['id' => $id, 'code' => 'IMP-'.strtoupper(substr(sha1($norm), 0, 8)), 'name_en' => $name, 'name_ar' => $name, 'type' => 'government', 'gender' => $this->schoolGender($name), 'stage' => $this->schoolStage($name),
                'region' => 'غير محدد', 'is_partner' => 0, 'status' => 'active', 'source' => 'moe_training_extract', 'created_at' => now(), 'updated_at' => now()];
        }
        if (! $dry) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('schools')->insertOrIgnore($chunk);
            }
            // a school that was already there by code (a re-run) is found again
            foreach ($names as $norm => $name) {
                $map[$norm] = DB::table('schools')->where('name_ar', $name)->value('id') ?? $map[$norm];
            }
        }
        $log(sprintf('Schools: %d matched, %d new.', $this->stats['schools_matched'], $this->stats['schools_created']));

        return $map;
    }

    private function schoolStage(string $n): string
    {
        return match (true) {
            (bool) preg_match('/رياض|روضة/u', $n) => 'kindergarten',
            (bool) preg_match('/ابتدائ|إبتدائ/u', $n) => 'primary',
            (bool) preg_match('/إعدادي|اعدادي/u', $n) => 'preparatory',
            (bool) preg_match('/ثانوي/u', $n) => 'secondary',
            default => 'multi',
        };
    }

    private function schoolGender(string $n): string
    {
        return match (true) {
            (bool) preg_match('/للبنين|للبنبن/u', $n) => 'boys',
            (bool) preg_match('/للبنات/u', $n) => 'girls',
            default => 'mixed',
        };
    }

    /** @param array<string, array<string, mixed>> $people @param array<string, string> $schools @return array<string, string> "school id|sub-unit" => department id */
    private function departments(array $people, array $schools, bool $dry): array
    {
        $map = [];
        $rows = [];
        foreach ($people as $p) {
            $school = $p['school'] ? ($schools[Cleanser::arabic($p['school'])] ?? null) : null;
            $sub = $p['sub'];
            if (! $school || $sub === '' || Cleanser::arabic($sub) === Cleanser::arabic((string) $p['school']) || in_array($sub, self::PSEUDO_ORGS, true)) {
                continue;
            }
            $key = $school.'|'.Cleanser::arabic($sub);
            if (isset($map[$key])) {
                continue;
            }
            $id = (string) Str::uuid7();
            $map[$key] = $id;
            $rows[] = ['id' => $id, 'school_id' => $school, 'code' => 'D'.strtoupper(substr(sha1($key), 0, 10)), 'name_en' => $sub, 'name_ar' => $sub, 'created_at' => now(), 'updated_at' => now()];
        }
        // A unit that is already there (same school, same code) is reused, so a second load adds nothing.
        $existing = [];
        if ($rows !== []) {
            foreach (array_chunk(array_column($rows, 'code'), 500) as $codes) {
                foreach (DB::table('departments')->whereIn('code', $codes)->orderBy('created_at')->get(['id', 'school_id', 'code']) as $d) {
                    $existing[$d->school_id.'|'.$d->code] ??= $d->id;
                }
            }
        }
        $fresh = [];
        foreach ($rows as $r) {
            if (isset($existing[$r['school_id'].'|'.$r['code']])) {
                $map[$r['school_id'].'|'.Cleanser::arabic($r['name_ar'])] = $existing[$r['school_id'].'|'.$r['code']];

                continue;
            }
            $fresh[] = $r;
            $this->stats['departments_created']++;
        }
        if (! $dry) {
            foreach (array_chunk($fresh, 200) as $chunk) {
                DB::table('departments')->insert($chunk);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $people
     * @param  array<string, string>  $jobCodes
     * @param  array<string, array<string, int>>  $votes
     * @return array<string, string> normalised job name => job title id
     */
    private function jobTitles(array $people, array $jobCodes, array $votes, bool $dry): array
    {
        $existing = [];
        foreach (DB::table('job_titles')->get(['id', 'name_ar']) as $j) {
            $existing[Cleanser::arabic($j->name_ar)] = $j->id;
        }
        $map = [];
        $rows = [];
        foreach ($people as $p) {
            $name = $p['job'];
            $norm = Cleanser::arabic($name);
            if ($name === '' || isset($map[$norm])) {
                continue;
            }
            if (isset($existing[$norm])) {
                $map[$norm] = $existing[$norm];
                $this->stats['job_titles_matched']++;

                continue;
            }
            $class = collect($votes[$norm] ?? [])->reject(fn ($v, $k) => str_starts_with((string) $k, '__seen_'))->sortDesc()->keys()->first() ?? $p['jobclass'];
            $id = (string) Str::uuid7();
            $map[$norm] = $id;
            $this->stats['job_titles_created']++;
            $code = isset($jobCodes[$norm]) && $jobCodes[$norm] !== '' ? 'J'.$jobCodes[$norm] : 'JN-'.strtoupper(substr(sha1($norm), 0, 8));
            $rows[] = ['id' => $id, 'code' => $code, 'name_en' => $name, 'name_ar' => $name, 'category' => $this->category($name, (string) $class), 'created_at' => now(), 'updated_at' => now()];
        }
        if (! $dry) {
            foreach ($rows as $r) {
                if (DB::table('job_titles')->where('code', $r['code'])->exists()) {
                    $r['code'] = 'JN-'.strtoupper(substr(sha1($r['name_ar']), 0, 8));
                }
                DB::table('job_titles')->insertOrIgnore([$r]);
                $map[Cleanser::arabic($r['name_ar'])] = DB::table('job_titles')->where('name_ar', $r['name_ar'])->value('id');
            }
        }

        return $map;
    }

    private function category(string $name, string $jobClass): string
    {
        return match (true) {
            $jobClass === 'قيادية' || (bool) preg_match('/مدير|نائب|قائد|رئيس/u', $name) => 'leadership',
            str_contains($jobClass, 'أكاديمية') => 'teaching',
            str_contains($jobClass, 'ادارية') => 'administrative',
            default => 'support',
        };
    }

    // ───────────────────────────── writing the people

    /**
     * @param  array<string, array<string, mixed>>  $people
     * @param  array<string, string>  $schools
     * @param  array<string, string>  $departments
     * @param  array<string, string>  $jobTitles
     * @return array<string, string> national ID => employee id
     */
    private function writePeople(array $people, array $schools, array $departments, array $jobTitles, string $source, bool $dry, callable $log): array
    {
        $roleId = Role::where('slug', Role::EMPLOYEE)->value('id');
        $byHash = $unlinked = $takenNos = $usedEmails = [];
        if (! $dry) {
            foreach (DB::table('employees')->get(['id', 'employee_no', 'national_id_hash', 'user_id']) as $e) {
                $takenNos[$e->employee_no] = true;
                if ($e->national_id_hash !== null) {
                    $byHash[$e->national_id_hash] = $e;
                } else {
                    $unlinked[$e->employee_no] = $e;   // an employee that is already there under this number and not yet tied to a national ID
                }
            }
            foreach (DB::table('users')->pluck('email') as $m) {
                $usedEmails[strtolower($m)] = true;
            }
        }
        $numbersTaken = $dry ? [] : DB::table('employee_numbers')->pluck('employee_id', 'employee_no')->all();
        $map = [];
        $done = 0;
        foreach (array_chunk($people, 400, true) as $chunk) {
            $users = $employees = $roles = $numbers = [];
            $updates = [];
            foreach ($chunk as $idno => $p) {
                $hash = Employee::nationalIdHash((string) $idno);
                $primary = $p['empno'];
                $existing = $byHash[$hash] ?? $unlinked[$primary] ?? null;
                $schoolId = $p['school'] ? ($schools[Cleanser::arabic($p['school'])] ?? null) : null;
                $fields = [
                    'school_id' => $schoolId, 'department_id' => $schoolId ? ($departments[$schoolId.'|'.Cleanser::arabic($p['sub'])] ?? null) : null, 'job_title_id' => $p['job'] !== '' ? ($jobTitles[Cleanser::arabic($p['job'])] ?? null) : null,
                    'job_class' => $p['jobclass'] ?: null, 'employment_class' => $p['empclass'] ?: null, 'employee_kind' => $p['kind'] ?: null, 'employee_type_code' => $p['emptype'] ?? null, 'employment_seq' => $p['seq'], 'sub_unit' => $p['sub'] ?: null,
                    'gender' => $p['sex'], 'nationality' => $this->nationality($p['nat']), 'hire_date' => $p['appointed'], 'education_level' => $p['eq'] ?: null, 'certificate_name' => $p['cert'], 'specialization' => $p['spec'],
                    'qualification' => self::QUALIFICATION[$p['eq'] ?? ''] ?? null, 'status' => $p['inactive'] ? 'inactive' : 'active', 'source_system' => $source, 'imported_at' => now(),
                    'experience_moe_years' => $p['first_appointed'] ? round(max(0, now()->diffInDays($p['first_appointed'], true)) / 365.25, 1) : null,
                ];
                $fields['experience_years'] = $fields['experience_moe_years'] ?? 0;
                $no = $primary;
                if ($existing) {
                    $employeeId = $existing->id;
                    $updates[] = [$employeeId, $existing->user_id, $fields + ['national_id_hash' => $hash], $p];
                    $this->stats['people_updated']++;
                } else {
                    $employeeId = (string) Str::uuid7();
                    $userId = (string) Str::uuid7();
                    $email = $p['email'];
                    if ($email === null || isset($usedEmails[$email])) {
                        $email = $primary.'@no-email.moe.invalid';
                        $this->stats['placeholder_emails']++;
                    }
                    $usedEmails[$email] = true;
                    $no = isset($takenNos[$primary]) ? $primary.'-'.substr((string) $idno, -4) : $primary;
                    $takenNos[$no] = true;
                    $users[] = ['id' => $userId, 'name' => $p['name'], 'name_ar' => $p['name'], 'email' => $email, 'phone' => $p['phone'], 'locale' => 'ar', 'status' => $p['inactive'] ? 'inactive' : 'active', 'failed_attempts' => 0, 'mfa_enabled' => 0,
                        'created_at' => now(), 'updated_at' => now()];
                    $employees[] = ['id' => $employeeId, 'user_id' => $userId, 'employee_no' => $no, 'national_id' => Crypt::encryptString((string) $idno), 'national_id_hash' => $hash, 'created_at' => now(), 'updated_at' => now()] + $fields;
                    if ($roleId) {
                        $roles[] = ['id' => (string) Str::uuid7(), 'role_id' => $roleId, 'user_id' => $userId, 'scope_type' => 'ministry', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                    }
                    $this->stats['people_created']++;
                }
                $map[(string) $idno] = $employeeId;
                $ownNo = $existing ? $existing->employee_no : $no;   // the number the employee goes by (made unique when another person already had it)
                if (! isset($numbersTaken[$ownNo])) {
                    $numbersTaken[$ownNo] = $employeeId;
                    $numbers[] = ['id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'employee_no' => $ownNo, 'seq' => $p['seq'], 'appointed_on' => $p['appointed'], 'employment_class' => $p['empclass'] ?: null,
                        'org_name' => $p['org'] ?: null, 'is_current' => 1, 'source_system' => $source, 'created_at' => now(), 'updated_at' => now()];
                }
                foreach ($p['records'] as $r) {
                    if (isset($numbersTaken[$r['empno']]) && $numbersTaken[$r['empno']] !== $employeeId) {
                        $this->issues[] = ['code' => 'empno_belongs_to_another_person', 'empno' => $r['empno']];

                        continue;
                    }
                    if (! isset($numbersTaken[$r['empno']])) {
                        $numbersTaken[$r['empno']] = $employeeId;
                        $numbers[] = ['id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'employee_no' => $r['empno'], 'seq' => $r['seq'], 'appointed_on' => $r['appointed'], 'employment_class' => $r['empclass'] ?: null,
                            'org_name' => $r['org'] ?: null, 'is_current' => $r['empno'] === $p['empno'] ? 1 : 0, 'source_system' => $source, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
            if (! $dry) {
                DB::transaction(function () use ($users, $employees, $roles, $numbers, $updates) {
                    foreach (array_chunk($users, 150) as $c) {
                        DB::table('users')->insert($c);
                    }
                    foreach (array_chunk($employees, 100) as $c) {
                        DB::table('employees')->insert($c);
                    }
                    foreach (array_chunk($roles, 150) as $c) {
                        DB::table('role_user')->insert($c);
                    }
                    foreach ($updates as [$employeeId, $userId, $fields, $p]) {
                        DB::table('employees')->where('id', $employeeId)->update($fields + ['updated_at' => now()]);
                        if ($userId) {
                            DB::table('users')->where('id', $userId)->update(array_filter(['name' => $p['name'], 'name_ar' => $p['name'], 'phone' => $p['phone'], 'updated_at' => now()], fn ($v) => $v !== null));
                        }
                    }
                    foreach (array_chunk($numbers, 150) as $c) {
                        DB::table('employee_numbers')->insert($c);
                    }
                });
            }
            $done += count($chunk);
            if ($done % 4000 < 400) {
                $log("People: {$done} / ".count($people));
            }
        }

        return $map;
    }

    /** @return array<string, string> the dictionary above, its keys normalised the way the extract's words are (hamza and yeh variants unified) */
    private function nationalityMap(): array
    {
        static $map = null;

        return $map ??= array_combine(array_map(fn ($k) => Cleanser::arabic($k), array_keys(self::NATIONALITY)), array_values(self::NATIONALITY));
    }

    private function nationality(string $adjective): ?string
    {
        if ($adjective === '') {
            return null;
        }
        if (preg_match('/غير محدد/u', $adjective)) {
            return null;                                       // "stateless / not specified": no nationality recorded
        }
        $n = Cleanser::arabic($adjective);
        $n = preg_replace('/ه$/u', '', $n) ?? $n;            // قطرية → قطريه → قطري
        $known = $this->nationalityMap();
        if (isset($known[$n])) {
            return $known[$n];
        }
        $this->unmappedNationalities[$adjective] = ($this->unmappedNationalities[$adjective] ?? 0) + 1;

        return $adjective;
    }

    // ───────────────────────────── the courses

    /**
     * @param  list<string>  $files
     * @param  array<string, string>  $idnoToEmployee
     */
    private function writeActivities(array $files, array $idnoToEmployee, string $typeId, string $source, bool $dry, callable $log): void
    {
        $batch = [];
        $seen = [];
        $now = now();
        $this->startedAt = $now;
        $flush = function () use (&$batch, $dry) {
            if ($batch === []) {
                return;
            }
            if ($dry) {
                $this->stats['activities_imported'] += count($batch);
                $this->stats['imported_hours'] += array_sum(array_column($batch, 'duration_hours'));
                $batch = [];

                return;
            }
            foreach (array_chunk($batch, 300) as $c) {
                $inserted = DB::table('pd_activities')->insertOrIgnore($c);
                $this->stats['activities_imported'] += $inserted;
                $this->stats['activities_existing'] += count($c) - $inserted;
                $this->stats['imported_hours'] += $inserted === count($c) ? array_sum(array_column($c, 'duration_hours'))
                    : (float) DB::table('pd_activities')->whereIn('row_hash', array_column($c, 'row_hash'))->where('created_at', '>=', $this->startedAt->toDateTimeString())->sum('duration_hours');
            }
            $batch = [];
        };

        foreach ($files as $fi => $file) {
            $log('Reading courses from '.basename($file).' …');
            foreach ($this->rows($file) as $n => $r) {
                $empno = $this->digits($r['empno']);
                $idno = $this->digits($r['idno']);
                if ($empno === '' || $idno === '' || Cleanser::name((string) $r['name']) === '' || ! isset($idnoToEmployee[$idno])) {
                    continue;   // identity rows were rejected in the first pass and are already counted
                }
                $title = Cleanser::name((string) $r['course']);
                $from = Cleanser::date($r['from']);
                $to = Cleanser::date($r['to']) ?? $from;
                $hours = Cleanser::number($r['hours']);
                $this->stats['source_hours'] += $hours ?? 0;
                $problem = match (true) {
                    $title === '' => 'course_name_missing',
                    $from === null => 'start_date_invalid',
                    $to < $from => 'end_before_start',
                    $hours === null || $hours <= 0 => 'hours_invalid',
                    default => null,
                };
                if ($problem) {
                    $this->stats['activities_rejected']++;
                    $this->issues[] = ['code' => $problem, 'empno' => $empno, 'row' => $n, 'file' => (string) $fi];

                    continue;
                }
                $hash = sha1(implode('|', [$idno, $empno, $title, $from, $to, $this->digits($r['jobno']), $hours]));
                if (isset($seen[$hash])) {
                    $this->stats['activities_existing']++;

                    continue;
                }
                $seen[$hash] = true;
                $batch[] = [
                    'id' => (string) Str::uuid7(), 'employee_id' => $idnoToEmployee[$idno], 'type_id' => $typeId, 'title' => $title, 'provider' => Cleanser::name((string) $r['provider']) ?: null, 'domain' => null, 'skill_ids' => '[]',
                    'starts_on' => $from, 'ends_on' => $to, 'duration_hours' => $hours, 'participation_level' => 'attendee', 'location' => Cleanser::name((string) $r['country']) ?: null, 'evidence' => null,
                    'computed_hours' => $hours, 'approved_hours' => $hours, 'status' => 'approved', 'decided_at' => $now, 'recognition_request' => 0, 'created_at' => $now, 'updated_at' => $now,
                    'source_system' => $source, 'row_hash' => $hash, 'source_empno' => $empno, 'course_type' => Cleanser::name((string) $r['ctype']) ?: null, 'track' => Cleanser::name((string) $r['track']) ?: null,
                    'external_ref' => $this->digits($r['jobno']) ?: null, 'external_ref_desc' => Cleanser::name((string) $r['jobdesc']) ?: null,
                ];
                if (count($batch) >= 1500) {
                    $flush();
                    $log(sprintf('Courses: %d imported …', $this->stats['activities_imported']));
                }
            }
        }
        $flush();
    }

    private ?Carbon $startedAt = null;
}
