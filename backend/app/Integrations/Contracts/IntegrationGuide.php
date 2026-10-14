<?php

namespace App\Integrations\Contracts;

use App\Integrations\IntegrationRegistry;

/**
 * The go-live guide of a system (docs/integrations/<system>.md), written from what the system's shipped contract already says: what the
 * Ministry provides, what to fill in TEDC, the test cases to run together with the expected results, and the way back. One source, so the
 * guide cannot drift from the contract. Arabic first, then English.
 */
final class IntegrationGuide
{
    /** The guides and the system each describes: file => key. */
    public const GUIDES = [
        'hr' => 'hr', 'licences' => 'licences', 'nsis' => 'nsis', 'qneds' => 'qneds', 'saaed' => 'saaed', 'sijil' => 'sijil',
        'ministry-site' => 'ministry_site', 'sms-gateway' => 'hudhud', 'moe-epay' => 'moe_epay',
    ];

    /** What the settings that live outside the integration card mean, for systems whose card does not list them. */
    private const OWN_SETTINGS = [
        'ministry_site' => [
            'base_url' => ['en' => 'The website\'s address, taken from the intake address (Communication → Ministry website).', 'ar' => 'عنوان الموقع، يُؤخذ من عنوان الاستقبال (التواصل ← موقع الوزارة).'],
            'publish_path' => ['en' => 'The path of the intake address.', 'ar' => 'مسار عنوان الاستقبال.'],
            'publish_query' => ['en' => 'A query string on the intake address, sent with every push.', 'ar' => 'معاملات الرابط في عنوان الاستقبال، تُرسل مع كل دفعة.'],
            'auth_header' => ['en' => 'The header the key travels in (X-Api-Key when empty).', 'ar' => 'الترويسة التي يُرسل فيها المفتاح (X-Api-Key إذا تُركت فارغة).'],
            'api_key' => ['en' => 'The key the website expects. Secret.', 'ar' => 'المفتاح الذي يتوقعه الموقع. سري.'],
            'site_name' => ['en' => 'The source name sent with every item.', 'ar' => 'اسم المصدر المرسل مع كل عنصر.'],
        ],
        'hudhud' => [
            'base_url' => ['en' => 'The gateway\'s address (Settings → Notification channels → SMS).', 'ar' => 'عنوان البوابة (الإعدادات ← قنوات الإشعارات ← الرسائل النصية).'],
            'send_path' => ['en' => 'The path messages are sent to.', 'ar' => 'مسار إرسال الرسائل.'],
            'api_key' => ['en' => 'The token, when the gateway uses one. Secret.', 'ar' => 'الرمز المميز إن كانت البوابة تستخدمه. سري.'],
            'username' => ['en' => 'The user name, when the gateway uses one instead of a token.', 'ar' => 'اسم المستخدم إن كانت البوابة تستخدمه بدل الرمز.'],
            'password' => ['en' => 'The password that goes with the user name. Secret.', 'ar' => 'كلمة المرور الخاصة باسم المستخدم. سري.'],
            'sender' => ['en' => 'The approved sender id.', 'ar' => 'اسم المرسل المعتمد.'],
            'receipt_url' => ['en' => 'The address TEDC gives the gateway for delivery receipts.', 'ar' => 'العنوان الذي يمنحه TEDC للبوابة لتقارير التسليم.'],
            'receipt_secret' => ['en' => 'The secret that signs delivery receipts. Secret.', 'ar' => 'السر الذي يوقّع تقارير التسليم. سري.'],
        ],
    ];

    /** What each common setting means, in both languages (the registry's own hints are English only). */
    private const DESCRIBED = [
        'base_url' => ['ar' => 'عنوان النظام (https) كما تسلّمه الوزارة، بلا مسار.', 'en' => 'The system\'s address (https) as the Ministry gives it, without a path.'],
        'api_key' => ['ar' => 'المفتاح أو الرمز الذي يصادق به TEDC عند الاتصال بالنظام.', 'en' => 'The key or token TEDC signs in with.'],
        'health_path' => ['ar' => 'مسار فحص الاتصال؛ يجيب بنجاح عندما يكون النظام سليمًا.', 'en' => 'The path of the connection check; it answers success when the system is well.'],
        'inbound_secret' => ['ar' => 'السر الذي يوقّع به النظام رسائله إلى TEDC (أو مفتاحه العام إن كان التوقيع RSA).', 'en' => 'The secret the system signs its messages to TEDC with (or its public key, for an RSA signature).'],
        'auth_header' => ['ar' => 'الترويسة (أو المعامل) التي يُرسل فيها المفتاح، حين يترك العقد ذلك للإعدادات.', 'en' => 'The header (or parameter) the key travels in, when the contract leaves it to the settings.'],
        'username' => ['ar' => 'اسم المستخدم.', 'en' => 'The user name.'],
        'password' => ['ar' => 'كلمة المرور.', 'en' => 'The password.'],
        'token_url' => ['ar' => 'العنوان الذي يمنح رموز الدخول (client credentials).', 'en' => 'The address that issues access tokens (client credentials).'],
        'client_id' => ['ar' => 'معرّف العميل لدى خدمة الرموز.', 'en' => 'The client id at the token service.'],
        'client_secret' => ['ar' => 'سر العميل لدى خدمة الرموز.', 'en' => 'The client secret at the token service.'],
        'signing_secret' => ['ar' => 'السر الذي يوقّع كل طلب.', 'en' => 'The secret that signs every request.'],
        'client_certificate' => ['ar' => 'شهادة العميل (PEM) للاتصال المتبادل.', 'en' => 'The client certificate (PEM) for mutual TLS.'],
        'client_key' => ['ar' => 'المفتاح الخاص بشهادة العميل (PEM).', 'en' => 'The private key of the client certificate (PEM).'],
        'conflict_policy' => ['ar' => 'عند الاختلاف: hr_wins (الموارد البشرية هي المرجع) أو tedc_wins (تملأ الموارد البشرية الفراغات فقط).', 'en' => 'When they differ: hr_wins (HR is the master) or tedc_wins (HR only fills blanks).'],
        'employees_path' => ['ar' => 'مسار قائمة الموظفين عند النظام.', 'en' => 'The path of the employee list at the system.'],
        'route_profile_changes' => ['ar' => 'عند الموافقة على تعديل لما تملكه الموارد البشرية يُرسل إليها بدل كتابته في TEDC.', 'en' => 'An approved change to something HR masters is sent to HR instead of being written in TEDC.'],
        'hr_mastered_fields' => ['ar' => 'ما تملكه الموارد البشرية حين تكون المرجع: للقراءة فقط في TEDC ويُعدَّل عبرها. المفاتيح: name_ar, name_en, job_title, school, department, grade_level, education_stage, qualification, specialization, hire_date, experience_years.', 'en' => 'What HR masters while it wins: read-only in TEDC, changed through HR. Keys: name_ar, name_en, job_title, school, department, grade_level, education_stage, qualification, specialization, hire_date, experience_years.'],
        'category_map' => ['ar' => 'جدول يطابق فئات البلاغات في TEDC بفئات سعيد (JSON).', 'en' => 'A table matching TEDC\'s report categories to Saaed\'s (JSON).'],
        'archive_certificates' => ['ar' => 'أرشفة الشهادات في سجل عند إصدارها.', 'en' => 'Archive certificates in Sijil when they are issued.'],
        'archive_records' => ['ar' => 'أرشفة سجلات البرامج (لاحقًا).', 'en' => 'Archive programme records (later).'],
        'merchant_id' => ['ar' => 'رقم التاجر لدى بوابة الدفع.', 'en' => 'The merchant id at the payment gateway.'],
        'secret' => ['ar' => 'السر المشترك الذي يوقّع الطلبات ونداءات البوابة (HMAC).', 'en' => 'The shared secret that signs requests and the gateway\'s callbacks (HMAC).'],
        'private_key' => ['ar' => 'RSA: مفتاح TEDC الخاص (PEM) الذي يوقّع الطلبات؛ تحتفظ الوزارة بالمفتاح العام.', 'en' => 'RSA: TEDC\'s private key (PEM) that signs requests; the Ministry keeps the public half.'],
        'callback_key' => ['ar' => 'RSA: المفتاح العام للوزارة (PEM) الذي يتحقق من نداءات البوابة.', 'en' => 'RSA: the Ministry\'s public key (PEM) that checks the gateway\'s callbacks.'],
    ];

    private const GROUPS = [
        'ministry_provides' => ['ar' => 'ما تقدّمه الوزارة', 'en' => 'What the Ministry provides'],
        'tedc_settings' => ['ar' => 'ما يُعدّ في TEDC', 'en' => 'What is set up in TEDC'],
        'joint_tests' => ['ar' => 'اختبارات الإطلاق مع الوزارة', 'en' => 'Tests to run with the Ministry'],
        'rollback' => ['ar' => 'خطة التراجع', 'en' => 'Rollback plan'],
    ];

    public function __construct(private readonly ContractStore $store) {}

    /** The whole guide of a system. */
    public function render(string $key): string
    {
        $contract = $this->store->bundled($key);
        $title = (array) ($contract->document()['title'] ?? []);
        $head = "# {$key} — ".($title['en'] ?? $key)."\n\n> Generated by `php artisan tedc:integration-docs` from the shipped contract (`backend/resources/contracts/{$key}`). Edit the contract, not this file.\n\n";

        return $head.$this->section($contract, $key, 'ar')."\n".$this->section($contract, $key, 'en');
    }

    private function section(Contract $contract, string $key, string $lang): string
    {
        $ar = $lang === 'ar';
        $doc = $contract->document();
        $t = fn (array $pair) => (string) ($pair[$lang] ?? $pair['en'] ?? '');
        $out = '## '.($ar ? 'العربية' : 'English')."\n\n";
        $out .= '**'.$t((array) ($doc['title'] ?? []))."**\n\n".$t((array) ($doc['description'] ?? []))."\n\n";

        $items = collect($contract->checklist())->groupBy('group');
        $list = fn (string $group) => $items->get($group, collect())->map(fn ($i) => '- [ ] '.$i[$lang])->implode("\n");

        $out .= '### '.self::GROUPS['ministry_provides'][$lang]."\n\n".$list('ministry_provides')."\n\n";
        $out .= '### '.self::GROUPS['tedc_settings'][$lang]."\n\n";
        $out .= ($ar ? "افتح البطاقة ثم املأ الإعدادات التالية، واضغط «فحص الاتصال». بعدها قارن العقد والمطابقة المرفقين بوثيقة الوزارة في تبويب «العقد» وعدّل ما يختلف (النسخ محفوظة ويمكن الرجوع إليها).\n\n" : "Open the card and fill in the settings below, then press “Check connection”. Then compare the shipped contract and mapping with the Ministry's document in the Contract tab and edit what differs (every save is a version you can go back to).\n\n");
        $out .= $this->settings($key, $contract, $lang)."\n";
        $out .= $ar ? '**المصادقة في العقد الحالي:** ' : '**Authentication in the shipped contract:** ';
        $out .= $this->auth((array) $contract->auth(), $ar)."\n\n";
        $out .= $this->operations($contract, $ar)."\n";
        if ($contract->inbound() !== []) {
            $out .= $this->inbound($contract, $ar)."\n";
        }

        $out .= '### '.self::GROUPS['joint_tests'][$lang]."\n\n";
        $out .= ($ar ? "شغّل هذه الحالات من تبويب «حالات الاختبار» أولًا على الإجابات المسجّلة، ثم على النظام الفعلي (القراءة فقط) بحضور الوزارة:\n\n" : "Run these cases from the Test cases tab first on the recorded answers, then (read-only operations) on the real system with the Ministry present:\n\n");
        $out .= $this->tests($contract, $ar)."\n";
        $steps = $list('joint_tests');
        $out .= $steps !== '' ? ($ar ? "**ما يُتحقق منه مع الوزارة:**\n\n" : "**To confirm with the Ministry:**\n\n").$steps."\n\n" : '';

        $out .= '### '.self::GROUPS['rollback'][$lang]."\n\n";
        $out .= $ar
            ? "- إيقاف التكامل من البطاقة (مفتاح التشغيل) يوقف كل الاستدعاءات فورًا ولا يحذف أي بيانات في TEDC.\n- العودة إلى نسخة العقد أو المطابقة السابقة من «العقد ← النسخ» (إنشاء نسخة جديدة مطابقة للقديمة)، أو «إعادة إلى المرفق» للعودة إلى ما يأتي مع TEDC.\n- التبديل إلى وضع التدريب (driver: fake) يعيد النظام إلى بيانات تجريبية دون أي اتصال خارجي.\n"
            : "- Switching the integration off on the card stops every call at once and deletes nothing in TEDC.\n- Go back to a previous version of the contract or mapping from Contract → Versions (it adds a new version equal to the old one), or “Reset to shipped” for what comes with TEDC.\n- Switching to the training mode (driver: fake) returns the system to sample data with no outside connection.\n";
        $steps = $list('rollback');
        $out .= $steps !== '' ? $steps."\n" : '';
        $out .= $ar
            ? "\n**قائمة التحقق:** تُسجَّل علامات التنفيذ (من ومتى) في تبويب «قائمة الإطلاق» في بطاقة التكامل.\n"
            : "\n**Checklist:** the ticks (who and when) are kept in the Go-live checklist tab of the integration card.\n";

        return $out;
    }

    private function settings(string $key, Contract $contract, string $lang): string
    {
        $secrets = $this->store->secretSettings($key);
        $rows = [];
        if (isset(self::OWN_SETTINGS[$key])) {
            foreach (self::OWN_SETTINGS[$key] as $name => $text) {
                $rows[] = [$name, in_array($name, $secrets, true), '', $text[$lang]];
            }
        } else {
            foreach ((array) (IntegrationRegistry::all()[$key]['fields'] ?? []) as $field) {
                if (str_starts_with((string) $field['k'], 'fake_')) {
                    continue;   // the training mode's sample data is not part of go-live
                }
                $default = $field['default'] ?? '';
                $note = (self::DESCRIBED[$field['k']][$lang] ?? null) ?: (string) ($field['hint'] ?? '');
                if (! empty($field['for_auth'])) {
                    $note .= ($note !== '' ? ' ' : '').($lang === 'ar' ? '(يُستخدم عندما تكون مصادقة العقد: ' : '(Used when the contract signs in with: ').implode(', ', (array) $field['for_auth']).')';
                }
                $rows[] = [$field['k'], ! empty($field['secret']), is_bool($default) ? ($default ? 'true' : 'false') : (is_array($default) ? (string) json_encode($default) : (string) $default), $note];
            }
        }
        $ar = $lang === 'ar';
        $out = '| '.($ar ? 'الإعداد' : 'Setting').' | '.($ar ? 'سري' : 'Secret').' | '.($ar ? 'الافتراضي' : 'Default').' | '.($ar ? 'الوصف' : 'Notes')." |\n|---|---|---|---|\n";
        foreach ($rows as [$name, $secret, $default, $hint]) {
            $out .= '| `'.$name.'` | '.($secret ? ($ar ? 'نعم' : 'yes') : '').' | '.($default !== '' ? '`'.str_replace('|', '\\|', $default).'`' : '').' | '.str_replace('|', '\\|', $hint)." |\n";
        }

        return $out;
    }

    /** @param  array<string, mixed>  $auth */
    private function auth(array $auth, bool $ar): string
    {
        $type = (string) ($auth['type'] ?? 'none');
        $names = [
            'none' => [$ar ? 'بلا مصادقة' : 'none'], 'bearer' => [$ar ? 'رمز Bearer في ترويسة Authorization' : 'a bearer token in the Authorization header'],
            'api_key' => [$ar ? 'مفتاح API في ترويسة أو معامل' : 'an API key in a header or query parameter'], 'basic' => [$ar ? 'اسم مستخدم وكلمة مرور (Basic)' : 'user name and password (Basic)'],
            'oauth2' => ['OAuth2 client credentials'], 'hmac' => [$ar ? 'توقيع HMAC/RSA لكل طلب' : 'an HMAC/RSA signature on every request'], 'mtls' => [$ar ? 'شهادة عميل (mTLS)' : 'a client certificate (mTLS)'],
        ];
        $text = $names[$type][0] ?? $type;
        if (isset($auth['fallback'])) {
            $text .= $ar ? '، وإن لم يوجد الرمز فاسم المستخدم وكلمة المرور' : ', and a user name and password when there is no token';
        }
        if (! empty($auth['optional'])) {
            $text .= $ar ? ' (اختياري: يُترك إن لم يُعبَّأ)' : ' (optional: left out when not set)';
        }

        return $text.'.';
    }

    private function operations(Contract $contract, bool $ar): string
    {
        $out = '**'.($ar ? 'العمليات' : 'Operations').":**\n\n| ".($ar ? 'العملية' : 'Operation').' | '.($ar ? 'الطلب' : 'Request').' | '.($ar ? 'للقراءة فقط' : 'Read-only')." |\n|---|---|---|\n";
        foreach ($contract->operations() as $name => $op) {
            $title = (array) ($op['title'] ?? []);
            $out .= '| `'.$name.'`'.($title ? ' — '.($title[$ar ? 'ar' : 'en'] ?? '') : '').' | `'.$op['method'].' '.str_replace('|', '\\|', (string) $op['path']).'` | '.(! empty($op['safe']) ? ($ar ? 'نعم' : 'yes') : '')." |\n";
        }

        return $out;
    }

    private function inbound(Contract $contract, bool $ar): string
    {
        $in = $contract->inbound();
        $s = (array) ($in['signature'] ?? []);
        $out = '**'.($ar ? 'الرسائل الواردة من النظام' : 'Messages coming in from the system').":**\n\n";
        if ($s !== []) {
            $out .= ($ar ? '- التوقيع: الترويسة ' : '- Signature: header ').'`'.($s['header'] ?? '').'`, '.($s['algorithm'] ?? 'hmac-sha256').', '.($s['encoding'] ?? 'hex')
                .(! empty($s['timestamp_header']) ? ($ar ? '، والوقت في '.'`'.$s['timestamp_header'].'`'.' (حتى '.($s['tolerance'] ?? 300).' ثانية)' : ', time in `'.$s['timestamp_header'].'` (up to '.($s['tolerance'] ?? 300).' seconds old)') : '')."\n";
        }
        if (isset($in['idempotency'])) {
            $out .= ($ar ? '- رقم الرسالة (لمنع التكرار): ' : '- Message number (so a repeat is harmless): ').'`'.($in['idempotency']['header'] ?? 'Idempotency-Key').'` / `'.($in['idempotency']['path'] ?? '$.id')."`\n";
        }
        if (isset($in['receipt'])) {
            $out .= ($ar ? '- تقارير التسليم تُقرأ عبر المطابقة (كيان receipt) وجدول الحالات.' : '- Delivery receipts are read through the mapping (the receipt entity) and its status table.')."\n";
        }

        return $out;
    }

    private function tests(Contract $contract, bool $ar): string
    {
        $out = '| '.($ar ? 'الحالة' : 'Case').' | '.($ar ? 'العملية' : 'Operation').' | '.($ar ? 'النتيجة المتوقعة' : 'Expected')." |\n|---|---|---|\n";
        foreach ($contract->tests() as $case) {
            $out .= '| '.str_replace('|', '\\|', (string) $case['name']).' | `'.$case['operation'].'` | '.str_replace('|', '\\|', $this->expected((array) ($case['expect'] ?? []), $ar))." |\n";
        }

        return $out;
    }

    /** @param  array<string, mixed>  $e */
    private function expected(array $e, bool $ar): string
    {
        $parts = [];
        if (isset($e['error'])) {
            $err = (array) $e['error'];
            $parts[] = ($ar ? 'خطأ واضح: ' : 'a clear error: ').($err['code'] ?? '').(isset($err['retryable']) ? ($err['retryable'] ? ($ar ? ' (يُعاد المحاولة)' : ' (retried)') : ($ar ? ' (دون إعادة)' : ' (not retried)')) : '');
        }
        if (isset($e['request'])) {
            $r = (array) $e['request'];
            $parts[] = ($ar ? 'الطلب ' : 'request ').trim(($r['method'] ?? '').' '.($r['path'] ?? ''));
        }
        if (isset($e['records'])) {
            $parts[] = ($ar ? 'السجلات: ' : 'records: ').$e['records'];
        }
        if (isset($e['values'])) {
            $parts[] = ($ar ? 'القيم: ' : 'values: ').implode(', ', array_map(fn ($k, $v) => $k.'='.(is_scalar($v) ? $v : json_encode($v)), array_keys((array) $e['values']), (array) $e['values']));
        }
        if (! empty($e['schema'])) {
            $parts[] = $ar ? 'مطابق للمخطط' : 'fits the schema';
        }

        return implode('; ', $parts);
    }
}
