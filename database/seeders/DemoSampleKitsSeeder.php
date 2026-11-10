<?php

namespace Database\Seeders;

use App\Models\KitFile;
use App\Models\KitMember;
use App\Models\KitReview;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\Kits\ImageGenerator;
use App\Services\Kits\KitLog;
use Illuminate\Support\Facades\Hash;

/**
 * Fifteen ready-made training kits — five for each kind of program (in person, online, hybrid) — so the Kit Studio can be
 * shown with finished work: every kit has its presentation, trainer guide, trainee handout, assessment, an activities
 * file for its kind of delivery and an illustration, an approved review, and is published against a program of the
 * same kind. Safe to run again: a kit that exists is left alone. One kit per call keeps each request short.
 */
class DemoSampleKitsSeeder extends DemoKitSeeder
{
    /** @return list<array{code: string, mode: string, ar: string, en: string, audience: string, hours: int, category: string, objectives: list<string>, guide: list<string>}> */
    public static function samples(): array
    {
        $rows = [
            ['in_person', 'حقيبة استراتيجيات التدريس النشط', 'Active Teaching Strategies Kit', 'معلمو المرحلة الابتدائية', 5, 'pedagogy', ['أن يطبق المعلم ثلاث استراتيجيات للتعلم النشط', 'أن يصمم أنشطة تعاونية قصيرة', 'أن يقيس مشاركة الطلاب أثناء الحصة']],
            ['in_person', 'حقيبة بناء الاختبارات وتحليل نتائجها', 'Test Design and Item Analysis Kit', 'معلمو المرحلة الإعدادية والثانوية', 5, 'assessment', ['أن يصمم المعلم اختباراً متوازن المستويات المعرفية', 'أن يحلل نتائج الاختبار لتحديد نقاط الضعف', 'أن يخطط للعلاج بناءً على التحليل']],
            ['in_person', 'حقيبة التعامل مع الفروق الفردية', 'Differentiated Instruction Kit', 'معلمو جميع المراحل', 5, 'pedagogy', ['أن يتعرف المعلم على أنماط تعلم طلابه', 'أن يعدّل المهام وفق مستويات الطلاب', 'أن يوفّر دعماً مناسباً للمتعثرين والمتفوقين']],
            ['in_person', 'حقيبة مهارات القيادة المدرسية', 'School Leadership Skills Kit', 'نواب المديرين ومنسقو المواد', 5, 'leadership', ['أن يضع القائد رؤية مشتركة لفريقه', 'أن يدير الاجتماعات المدرسية بفاعلية', 'أن يقدّم تغذية راجعة بنّاءة للمعلمين']],
            ['in_person', 'حقيبة الإدارة الصفية الإيجابية', 'Positive Classroom Management Kit', 'معلمو المرحلة الابتدائية', 5, 'pedagogy', ['أن يضع المعلم قواعد صفية بمشاركة الطلاب', 'أن يعزّز السلوك الإيجابي', 'أن يعالج المشكلات السلوكية بهدوء']],

            ['online', 'حقيبة التحول الرقمي في التدريس', 'Digital Transformation in Teaching Kit', 'معلمو جميع المراحل', 4, 'digital', ['أن يوظف المعلم أدوات رقمية في الدرس', 'أن يدير الصف عبر منصة تعليمية', 'أن يتابع تقدم الطلاب رقمياً']],
            ['online', 'حقيبة تصميم المحتوى الإلكتروني التفاعلي', 'Interactive E-content Design Kit', 'معلمو المرحلة الإعدادية والثانوية', 4, 'digital', ['أن يصمم المعلم درساً إلكترونياً قصيراً', 'أن يدمج أسئلة تفاعلية في المحتوى', 'أن يراعي معايير جودة المحتوى الرقمي']],
            ['online', 'حقيبة أدوات التقويم الإلكتروني', 'Online Assessment Tools Kit', 'المعلمون ومنسقو المواد', 4, 'assessment', ['أن يبني المعلم اختباراً إلكترونياً', 'أن يستخدم بنوك الأسئلة', 'أن يحلل نتائج التقويم الإلكتروني']],
            ['online', 'حقيبة الأمن الرقمي لمعلمي المدارس', 'Digital Security for Teachers Kit', 'جميع العاملين بالمدرسة', 3, 'digital', ['أن يحمي المعلم حساباته وبياناته', 'أن يكتشف رسائل التصيّد الاحتيالي', 'أن يرشد الطلاب إلى السلوك الرقمي الآمن']],
            ['online', 'حقيبة التعلم المصغّر بالفيديو', 'Video Micro-learning Kit', 'معلمو جميع المراحل', 3, 'digital', ['أن ينتج المعلم فيديو تعليمياً قصيراً', 'أن يربط الفيديو بنشاط تقويمي', 'أن ينشره ويتابع مشاهداته']],

            ['hybrid', 'حقيبة التعلم المدمج وتصميم الدرس', 'Blended Learning and Lesson Design Kit', 'معلمو المرحلة الثانوية', 6, 'digital', ['أن يصمم المعلم تجربة تعلم مدمج متكاملة', 'أن يوزّع الأنشطة بين الصف والمنصة', 'أن يقيّم أثر التعلم المدمج']],
            ['hybrid', 'حقيبة القيادة التربوية الحديثة', 'Modern Educational Leadership Kit', 'مديرو المدارس ونوابهم', 6, 'leadership', ['أن يقود المدير التغيير في مدرسته', 'أن يبني ثقافة التعلم المهني', 'أن يستخدم البيانات في اتخاذ القرار']],
            ['hybrid', 'حقيبة التدريس بالمشاريع', 'Project-based Teaching Kit', 'معلمو المرحلة الإعدادية', 6, 'pedagogy', ['أن يصمم المعلم مشروعاً تعليمياً متكاملاً', 'أن يدير فرق الطلاب أثناء المشروع', 'أن يقيّم المنتج النهائي بمعايير واضحة']],
            ['hybrid', 'حقيبة التوجيه والإرشاد الأكاديمي', 'Academic Guidance and Counselling Kit', 'الأخصائيون الاجتماعيون والمرشدون', 5, 'wellbeing', ['أن يتعرف المرشد على احتياجات الطلاب الأكاديمية', 'أن يضع خطط دعم فردية', 'أن يتواصل مع أولياء الأمور بفاعلية']],
            ['hybrid', 'حقيبة بناء مجتمعات التعلم المهنية', 'Professional Learning Communities Kit', 'منسقو المواد والمعلمون الأوائل', 5, 'leadership', ['أن يؤسس المنسق مجتمع تعلم مهني في مدرسته', 'أن يدير دراسة درس مشتركة', 'أن يوثّق أثر التعلم المهني']],
        ];
        $prefix = ['in_person' => 'IP', 'online' => 'ON', 'hybrid' => 'HY'];
        $count = [];
        $out = [];
        foreach ($rows as [$mode, $ar, $en, $audience, $hours, $category, $objectives]) {
            $k = $count[$mode] = ($count[$mode] ?? 0) + 1;
            $out[] = ['code' => "KIT-S-{$prefix[$mode]}{$k}", 'mode' => $mode, 'ar' => $ar, 'en' => $en, 'audience' => $audience, 'hours' => $hours, 'category' => $category, 'objectives' => $objectives,
                'guide' => match ($mode) {
                    'online' => ['تهيئة المنصة وفحص الاتصال', 'افتتاح الجلسة وقواعد المشاركة', 'الأنشطة التفاعلية عن بُعد', 'إدارة الأسئلة والدردشة', 'التقويم الختامي وجمع الملاحظات'],
                    'hybrid' => ['تجهيز القاعة والمنصة معاً', 'اللقاء الحضوري: افتتاح وأنشطة', 'الجلسة الإلكترونية: متابعة وتطبيق', 'الربط بين اللقاءين', 'التقويم الختامي'],
                    default => ['تجهيز القاعة وترتيب المقاعد', 'افتتاح الجلسة', 'الأنشطة الجماعية', 'إدارة النقاش', 'التقويم الختامي'],
                }];
        }

        return $out;
    }

    public function run(): void
    {
        foreach (array_keys(self::samples()) as $i) {
            $this->build($i + 1);
        }
    }

    /** Builds sample kit number 1…15 (idempotent). Returns the kit. */
    public function build(int $n): ?TrainingKit
    {
        $sample = self::samples()[$n - 1] ?? null;
        if (! $sample) {
            return null;
        }
        if ($existing = TrainingKit::where('code', $sample['code'])->first()) {
            return $existing;
        }
        // The pictures are drawn offline; no outside service is called while samples are built.
        config(['tedc.kits.image_provider' => 'placeholder']);

        [$dev, $qa] = $this->people($n);
        $admin = User::where('email', 'center@tedc.qa')->first() ?? User::first();
        $program = $this->programFor($sample);

        $kit = TrainingKit::create([
            'code' => $sample['code'], 'title_ar' => $sample['ar'], 'title_en' => $sample['en'], 'program_id' => $program->id, 'category_id' => ProgramCategory::where('slug', $sample['category'])->value('id') ?? $program->category_id,
            'delivery' => $program->delivery_mode, 'status' => TrainingKit::DRAFT, 'audience' => $sample['audience'], 'duration_hours' => $sample['hours'], 'objectives' => $sample['objectives'],
            'tags' => [['in_person' => 'حضوري', 'online' => 'عن بُعد', 'hybrid' => 'مدمج'][$sample['mode']], 'نموذجية'], 'owner_id' => $dev->id, 'created_by' => $admin?->id, 'due_at' => now()->subDays(3),
            'description_ar' => "حقيبة جاهزة لتدريب {$sample['audience']}: عرض تقديمي، دليل المدرب، دليل المتدرب، أدوات التقويم وملف الأنشطة.",
        ]);
        KitMember::create(['kit_id' => $kit->id, 'user_id' => $qa->id, 'role' => KitMember::QA]);
        KitLog::record($kit, $dev, 'created', 'kit', $kit->id);

        $deck = $this->deck($kit, $dev, 'عرض: '.$sample['ar'], $sample['mode'] === 'online' ? 12 : 14);
        $this->picture($kit, $dev, $sample['ar'].' — لقطة رئيسية', $deck);
        $this->guide($kit, $dev, 'دليل المدرب — '.$sample['ar'], 'trainer_guide', $sample['guide']);
        $this->handout($kit, $dev, 'دليل المتدرب — '.$sample['ar'], 'handout');
        $this->handout($kit, $dev, 'أدوات التقويم — '.$sample['ar'], 'assessment');
        $this->guide($kit, $dev, match ($sample['mode']) {
            'online' => 'دليل الأنشطة التفاعلية عن بُعد',
            'hybrid' => 'دليل الربط بين اللقاء الحضوري والجلسة الإلكترونية',
            default => 'بطاقات الأنشطة الصفية',
        }, 'activity', ['نشاط الافتتاح', 'نشاط المجموعات', 'نشاط الختام']);
        $this->illustration($kit, $dev, $sample['ar']);

        KitReview::create(['kit_id' => $kit->id, 'round' => 1, 'status' => 'approved', 'submitted_by' => $dev->id, 'decided_by' => $qa->id, 'submitted_at' => now()->subDays(6), 'decided_at' => now()->subDays(4),
            'note' => 'استوفت الحقيبة معايير الجودة وهي جاهزة للنشر.', 'summary' => ['open' => 0, 'addressed' => 0, 'resolved' => 0, 'blocking' => 0]]);
        KitLog::record($kit, $dev, 'submitted', 'kit', $kit->id, ['round' => 1]);
        KitLog::record($kit, $qa, 'approved', 'kit', $kit->id, ['round' => 1]);
        KitLog::record($kit, $admin ?? $qa, 'published', 'kit', $kit->id);
        $kit->update(['status' => TrainingKit::PUBLISHED, 'review_round' => 1, 'version' => 2, 'submitted_at' => now()->subDays(6), 'approved_at' => now()->subDays(4), 'approved_by' => $qa->id, 'published_at' => now()->subDays(3)]);

        return $kit->refresh();
    }

    /** @return array{0: User, 1: User} */
    private function people(int $n): array
    {
        $hash = Hash::make(self::PASSWORD);
        $person = function (string $email, string $ar, string $en, string $role) use ($hash) {
            $user = User::updateOrCreate(['email' => $email], ['name' => $en, 'name_ar' => $ar, 'password' => $hash, 'locale' => 'ar', 'status' => 'active']);
            $user->roles()->syncWithoutDetaching(Role::where('slug', $role)->pluck('id'));

            return $user;
        };
        $devs = [$person('kits@tedc.qa', 'نورة الجابر', 'Noora Al-Jaber', Role::KIT_DEVELOPER), $person('kits2@tedc.qa', 'عبدالله المسلماني', 'Abdullah Al-Muslimani', Role::KIT_DEVELOPER)];
        $qas = [$person('qa@tedc.qa', 'خالد الدوسري', 'Khalid Al-Dosari', Role::QA_REVIEWER), $person('qa2@tedc.qa', 'مها الكعبي', 'Maha Al-Kaabi', Role::QA_REVIEWER)];

        return [$devs[$n % 2], $qas[intdiv($n, 2) % 2]];
    }

    /** A program of the same kind with no kit yet: an existing one when there is, otherwise a small sample program. */
    private function programFor(array $sample): Program
    {
        $free = Program::where('delivery_mode', $sample['mode'])->where('code', 'not like', 'TEST-%')->where('code', 'not like', 'SC-%')->where('code', 'not like', 'SMP-%')
            ->whereNotIn('id', TrainingKit::whereNotNull('program_id')->select('program_id'))->orderBy('code')->first();
        if ($free) {
            return $free;
        }

        $start = today()->addDays(21);
        $program = Program::updateOrCreate(['code' => 'SMP-'.substr($sample['code'], 6)], [
            'category_id' => ProgramCategory::where('slug', $sample['category'])->value('id') ?? ProgramCategory::query()->value('id'),
            'title_ar' => str_replace('حقيبة ', 'برنامج ', $sample['ar']), 'title_en' => str_replace(' Kit', '', $sample['en']), 'summary_ar' => 'برنامج نموذجي مرتبط بحقيبة جاهزة.', 'summary_en' => 'A sample program linked to a ready kit.',
            'objectives' => $sample['objectives'], 'delivery_mode' => $sample['mode'], 'level' => 'intermediate', 'total_hours' => 10, 'capacity' => 30, 'min_attendance_percent' => 80, 'requires_tasks' => false, 'requires_evaluation' => false,
            'start_date' => $start, 'end_date' => $start->copy()->addDays(2), 'registration_opens_at' => today(), 'registration_closes_at' => $start->copy()->endOfDay(), 'registration_modes' => Program::MODES, 'status' => Program::STATUS_REGISTRATION_OPEN,
        ]);
        if ($program->sessions()->doesntExist()) {
            foreach ([0, 2] as $i => $offset) {
                $online = $sample['mode'] === 'online' || ($sample['mode'] === 'hybrid' && $i === 1);
                ProgramSession::create([
                    'program_id' => $program->id, 'sequence' => $i + 1, 'title_ar' => 'اللقاء '.($i + 1), 'title_en' => 'Session '.($i + 1), 'starts_at' => $start->copy()->addDays($offset)->setTime(8, 0), 'ends_at' => $start->copy()->addDays($offset)->setTime(13, 0),
                    'status' => 'scheduled', 'mode' => $online ? 'online' : 'in_person', 'online_platform' => $online ? 'teams' : null, 'location_text' => $online ? null : 'مركز التدريب',
                ]);
            }
        }

        return $program;
    }

    /** The kit's illustration as a file of its own (the "media" part of a complete kit). */
    private function illustration(TrainingKit $kit, User $dev, string $topic): void
    {
        $asset = app(ImageGenerator::class)->generate($topic.' — رسم توضيحي للحقيبة', $kit, $dev, ['aspect' => '16:9']);
        $file = KitFile::create(['kit_id' => $kit->id, 'name' => 'رسم توضيحي — '.$topic, 'original_name' => 'illustration.svg', 'kind' => 'image', 'category' => 'media', 'source' => 'generated', 'mime' => $asset->mime, 'size' => $asset->size,
            'storage_path' => $asset->storage_path, 'uploaded_by' => $dev->id, 'updated_by' => $dev->id, 'sort_order' => KitFile::where('kit_id', $kit->id)->count() + 1]);
        KitLog::record($kit, $dev, 'file_uploaded', 'file', $file->id, ['name' => $file->name, 'kind' => 'image']);
    }
}
