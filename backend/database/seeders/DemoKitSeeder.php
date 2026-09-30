<?php

namespace Database\Seeders;

use App\Models\KitAsset;
use App\Models\KitComment;
use App\Models\KitFile;
use App\Models\KitMember;
use App\Models\KitReview;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\FileStorage;
use App\Services\Kits\DeckBuilder;
use App\Services\Kits\DeckGenerator;
use App\Services\Kits\ImageGenerator;
use App\Services\Kits\KitFiles;
use App\Services\Kits\KitLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use ZipArchive;

/**
 * Sample data for the Training Kit Studio: developers, QA reviewers and kits at every stage of the
 * lifecycle - with decks, a Word guide, a PDF handout, pictures, review rounds and comment threads.
 */
class DemoKitSeeder extends Seeder
{
    private const PASSWORD = 'Tedc@2026!';

    public function run(): void
    {
        if (TrainingKit::exists()) {
            return;
        }

        $hash = Hash::make(self::PASSWORD);
        $person = function (string $email, string $ar, string $en, string $role) use ($hash) {
            $user = User::updateOrCreate(['email' => $email], ['name' => $en, 'name_ar' => $ar, 'password' => $hash, 'locale' => 'ar', 'status' => 'active']);
            $user->roles()->syncWithoutDetaching(Role::where('slug', $role)->pluck('id'));

            return $user;
        };
        $dev1 = $person('kits@tedc.qa', 'نورة الجابر', 'Noora Al-Jaber', Role::KIT_DEVELOPER);
        $dev2 = $person('kits2@tedc.qa', 'عبدالله المسلماني', 'Abdullah Al-Muslimani', Role::KIT_DEVELOPER);
        $qa1 = $person('qa@tedc.qa', 'خالد الدوسري', 'Khalid Al-Dosari', Role::QA_REVIEWER);
        $qa2 = $person('qa2@tedc.qa', 'مها الكعبي', 'Maha Al-Kaabi', Role::QA_REVIEWER);
        $admin = User::where('email', 'center@tedc.qa')->first() ?? User::first();

        $kits = [
            ['CLM-110', 'حقيبة إدارة الصف الفعّال', 'Effective Classroom Management Kit', 'معلمو المرحلة الابتدائية والإعدادية', 6, TrainingKit::PUBLISHED, $dev1, $qa1, 'pedagogy',
                ['أن يطبق المعلم استراتيجيات ضبط السلوك الإيجابي', 'أن يصمم قواعد صفية بمشاركة الطلاب', 'أن يقيّم أثر إدارة الصف على تعلم الطلاب'], -20],
            [null, 'حقيبة الذكاء الاصطناعي في التعليم', 'AI in Education Kit', 'معلمو جميع المراحل', 8, TrainingKit::IN_REVIEW, $dev1, $qa1, 'digital',
                ['أن يوظف المعلم أدوات الذكاء الاصطناعي في إعداد الدروس', 'أن يتحقق المعلم من دقة المحتوى المولّد', 'أن يراعي المعلم أخلاقيات استخدام الذكاء الاصطناعي مع الطلاب'], 5],
            [null, 'حقيبة التقويم من أجل التعلم', 'Assessment for Learning Kit', 'المعلمون الأوائل ومنسقو المواد', 6, TrainingKit::CHANGES_REQUESTED, $dev2, $qa2, 'assessment',
                ['أن يصمم المعلم أدوات تقويم تكويني متنوعة', 'أن يحلل نتائج التقويم لتحسين التدريس', 'أن يقدم تغذية راجعة فعّالة للطلاب'], 9],
            ['BLD-140', 'حقيبة التعلم المدمج والمنصات الرقمية', 'Blended Learning Kit', 'معلمو المرحلة الثانوية', 12, TrainingKit::IN_DEVELOPMENT, $dev1, $qa2, 'digital',
                ['أن يصمم المعلم تجربة تعلم مدمج متكاملة', 'أن يستخدم المعلم المنصات الرقمية لإدارة التعلم'], 21],
            [null, 'حقيبة رفاه الطلاب والدعم النفسي', 'Student Wellbeing Kit', 'الأخصائيون الاجتماعيون والنفسيون', 4, TrainingKit::DRAFT, $dev2, $qa1, 'wellbeing', ['أن يتعرف الأخصائي على مؤشرات الضغط النفسي لدى الطلاب'], 35],
        ];

        foreach ($kits as $i => [$programCode, $ar, $en, $audience, $hours, $status, $dev, $qa, $categorySlug, $objectives, $due]) {
            $program = $programCode ? Program::where('code', $programCode)->first() : null;
            $kit = TrainingKit::create([
                'code' => 'KIT-'.now()->format('y').'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'title_ar' => $ar, 'title_en' => $en, 'audience' => $audience, 'duration_hours' => $hours,
                'program_id' => $program?->id, 'category_id' => ProgramCategory::where('slug', $categorySlug)->value('id'), 'objectives' => $objectives, 'status' => TrainingKit::DRAFT,
                'owner_id' => $dev->id, 'created_by' => $admin?->id, 'due_at' => now()->addDays($due), 'version' => $status === TrainingKit::PUBLISHED ? 2 : 1,
                'description_ar' => "حقيبة متكاملة لتدريب {$audience}: عرض تقديمي، دليل المدرب، دليل المتدرب وأدوات التقويم.",
            ]);
            KitMember::create(['kit_id' => $kit->id, 'user_id' => $qa->id, 'role' => KitMember::QA]);
            KitLog::record($kit, $dev, 'created', 'kit', $kit->id);

            match ($i) {
                0 => $this->publishedKit($kit, $dev, $qa, $admin),
                1 => $this->inReviewKit($kit, $dev, $qa),
                2 => $this->changesKit($kit, $dev, $qa),
                3 => $this->developmentKit($kit, $dev),
                default => null,
            };
            $kit->update(['status' => $status]);
            if ($status === TrainingKit::PUBLISHED) {
                $kit->update(['approved_at' => now()->subDays(6), 'approved_by' => $qa->id, 'published_at' => now()->subDays(5), 'review_round' => 2]);
            }
        }
        unset($qa2);
    }

    // Kits at each stage -----------------------------------------------------------------------

    private function publishedKit(TrainingKit $kit, User $dev, User $qa, ?User $admin): void
    {
        $deck = $this->deck($kit, $dev, 'عرض إدارة الصف الفعّال', 14);
        $this->guide($kit, $dev, 'دليل المدرب — إدارة الصف', 'trainer_guide', ['التحضير القبلي', 'افتتاح الجلسة', 'الأنشطة الجماعية', 'التقويم الختامي']);
        $this->handout($kit, $dev, 'دليل المتدرب — إدارة الصف', 'handout');
        $this->picture($kit, $dev, 'معلمة تدير صفاً نشطاً بابتسامة', $deck);
        $this->picture($kit, $dev, 'طلاب يعملون في مجموعات صغيرة', $deck);

        $slides = collect($deck->content['slides']);
        $r1 = KitReview::create(['kit_id' => $kit->id, 'round' => 1, 'status' => 'changes_requested', 'submitted_by' => $dev->id, 'decided_by' => $qa->id, 'submitted_at' => now()->subDays(16), 'decided_at' => now()->subDays(13),
            'note' => 'المحتوى جيد، لكن تحتاج بعض الشرائح إلى تبسيط وإضافة مصدر للمعلومات الإحصائية.', 'summary' => ['open' => 2, 'addressed' => 0, 'resolved' => 1, 'blocking' => 1]]);
        KitReview::create(['kit_id' => $kit->id, 'round' => 2, 'status' => 'approved', 'submitted_by' => $dev->id, 'decided_by' => $qa->id, 'submitted_at' => now()->subDays(9), 'decided_at' => now()->subDays(6),
            'note' => 'استوفت الحقيبة معايير الجودة. شكراً على سرعة الاستجابة.', 'summary' => ['open' => 0, 'addressed' => 0, 'resolved' => 4, 'blocking' => 0]]);
        KitLog::record($kit, $dev, 'submitted', 'kit', $kit->id, ['round' => 1]);
        KitLog::record($kit, $qa, 'changes_requested', 'kit', $kit->id, ['round' => 1, 'note' => $r1->note]);
        KitLog::record($kit, $dev, 'submitted', 'kit', $kit->id, ['round' => 2]);
        KitLog::record($kit, $qa, 'approved', 'kit', $kit->id, ['round' => 2]);
        KitLog::record($kit, $admin ?? $qa, 'published', 'kit', $kit->id);

        $this->thread($kit, $deck, $qa, $dev, $slides[3]['id'] ?? null, 'المعلومة الإحصائية هنا تحتاج إلى مصدر موثوق.', 'accuracy', 'major', 'resolved', 'أضفت المصدر في ملاحظات المدرب.', 1, -14);
        $this->thread($kit, $deck, $qa, $dev, $slides[5]['id'] ?? null, 'الشريحة مزدحمة، اقترح تقسيمها.', 'design', 'minor', 'resolved', 'قسّمتها إلى شريحتين.', 1, -14);
        $this->thread($kit, $deck, $qa, $dev, $slides[8]['id'] ?? null, 'أضف نشاطاً تفاعلياً لتعزيز الفكرة.', 'content', 'info', 'resolved', 'أُضيف نشاط المجموعات.', 2, -8);
        $this->thread($kit, $deck, $qa, $dev, $slides[1]['id'] ?? null, 'صياغة الهدف الثاني تحتاج إلى فعل قابل للقياس.', 'language', 'minor', 'resolved', 'عُدّلت الصياغة.', 2, -8);
    }

    private function inReviewKit(TrainingKit $kit, User $dev, User $qa): void
    {
        $deck = $this->deck($kit, $dev, 'عرض الذكاء الاصطناعي في التعليم', 16);
        $this->guide($kit, $dev, 'دليل المدرب — الذكاء الاصطناعي', 'trainer_guide', ['أدوات الذكاء الاصطناعي المتاحة', 'كتابة الأوامر الفعّالة', 'التحقق من المخرجات', 'الأخلاقيات والخصوصية']);
        $this->picture($kit, $dev, 'معلم يستخدم مساعداً ذكياً لإعداد درس', $deck);
        KitReview::create(['kit_id' => $kit->id, 'round' => 1, 'status' => 'in_progress', 'submitted_by' => $dev->id, 'submitted_at' => now()->subDays(2), 'note' => 'الحقيبة جاهزة للمراجعة الأولى، نرجو التركيز على دقة الأمثلة.']);
        $kit->update(['review_round' => 1, 'submitted_at' => now()->subDays(2)]);
        KitLog::record($kit, $dev, 'submitted', 'kit', $kit->id, ['round' => 1, 'note' => 'الحقيبة جاهزة للمراجعة الأولى']);

        $slides = collect($deck->content['slides']);
        $this->thread($kit, $deck, $qa, $dev, $slides[2]['id'] ?? null, 'تعريف الذكاء الاصطناعي هنا غير دقيق؛ يرجى الرجوع إلى مصدر معتمد.', 'accuracy', 'critical', 'open', null, 1, -1, 120, 240);
        $this->thread($kit, $deck, $qa, $dev, $slides[4]['id'] ?? null, 'المثال المذكور لا يناسب الفئة المستهدفة، استبدله بمثال من الصف الدراسي.', 'content', 'major', 'addressed', 'استبدلته بمثال عن إعداد اختبار قصير.', 1, -1);
        $this->thread($kit, $deck, $qa, $dev, $slides[6]['id'] ?? null, 'حجم الخط صغير جداً لا يُقرأ من آخر القاعة.', 'design', 'minor', 'open', null, 1, 0, 700, 520);
        $this->thread($kit, $deck, $qa, $dev, $slides[9]['id'] ?? null, 'يرجى الإشارة إلى سياسات وزارة التربية بشأن أدوات الذكاء الاصطناعي.', 'alignment', 'major', 'open', null, 1, 0);
        $this->thread($kit, $deck, $qa, $dev, null, 'دليل المدرب ينقصه توقيت لكل نشاط.', 'content', 'minor', 'open', null, 1, 0, null, null, KitFile::where('kit_id', $kit->id)->where('category', 'trainer_guide')->value('id'));
    }

    private function changesKit(TrainingKit $kit, User $dev, User $qa): void
    {
        $deck = $this->deck($kit, $dev, 'عرض التقويم من أجل التعلم', 12);
        $this->handout($kit, $dev, 'أدوات التقويم التكويني', 'assessment');
        KitReview::create(['kit_id' => $kit->id, 'round' => 1, 'status' => 'changes_requested', 'submitted_by' => $dev->id, 'decided_by' => $qa->id, 'submitted_at' => now()->subDays(6), 'decided_at' => now()->subDays(3),
            'note' => 'الهيكل ممتاز لكن أدوات التقويم غير مرتبطة بالأهداف بوضوح. يرجى معالجة الملاحظات الحرجة.', 'summary' => ['open' => 3, 'addressed' => 0, 'resolved' => 0, 'blocking' => 2]]);
        $kit->update(['review_round' => 1, 'submitted_at' => now()->subDays(6)]);
        KitLog::record($kit, $dev, 'submitted', 'kit', $kit->id, ['round' => 1]);
        KitLog::record($kit, $qa, 'changes_requested', 'kit', $kit->id, ['round' => 1, 'note' => 'الهيكل ممتاز لكن أدوات التقويم غير مرتبطة بالأهداف']);

        $slides = collect($deck->content['slides']);
        $this->thread($kit, $deck, $qa, $dev, $slides[3]['id'] ?? null, 'أدوات التقويم هنا غير مرتبطة بالهدف الثاني.', 'alignment', 'critical', 'open', null, 1, -3, 300, 300);
        $this->thread($kit, $deck, $qa, $dev, $slides[7]['id'] ?? null, 'المثال يحتاج توضيحاً أكثر لمعيار الحكم على الأداء.', 'content', 'major', 'open', 'سأضيف مثالاً محلولاً كاملاً.', 1, -3);
        $this->thread($kit, $deck, $qa, $dev, $slides[10]['id'] ?? null, 'خطأ إملائي في العنوان.', 'language', 'minor', 'open', null, 1, -3);
    }

    private function developmentKit(TrainingKit $kit, User $dev): void
    {
        $this->deck($kit, $dev, 'عرض التعلم المدمج', 10);
        KitLog::record($kit, $dev, 'commented', 'kit', $kit->id);
    }

    // Building blocks ---------------------------------------------------------------------------

    private function deck(TrainingKit $kit, User $dev, string $name, int $slides): KitFile
    {
        $outline = app(DeckGenerator::class)->template([
            'topic' => $kit->title_ar, 'audience' => $kit->audience, 'objectives' => $kit->objectives, 'slide_count' => $slides, 'language' => 'ar', 'duration_hours' => $kit->duration_hours, 'activities' => true, 'quiz' => true,
        ]);
        $deck = app(DeckBuilder::class)->build($outline, 'ar');

        return app(KitFiles::class)->createDeck($kit, $dev, $name, $deck, 'generated');
    }

    /** Puts a generated picture into the first empty image frame of the deck. */
    private function picture(TrainingKit $kit, User $dev, string $prompt, KitFile $deck): void
    {
        $asset = app(ImageGenerator::class)->generate($prompt, $kit, $dev, ['aspect' => '4:3']);
        $content = $deck->content;
        foreach ($content['slides'] as &$slide) {
            foreach ($slide['elements'] as &$el) {
                if (($el['type'] ?? null) === 'image' && empty($el['asset_id'])) {
                    $el['asset_id'] = $asset->id;
                    $el['alt'] = $prompt;
                    $deck->update(['content' => $content]);

                    return;
                }
            }
            unset($el);
        }
        unset($slide);
        KitAsset::whereKey($asset->id)->update(['source' => 'generated']);
    }

    private function guide(TrainingKit $kit, User $user, string $name, string $category, array $sections): KitFile
    {
        $paragraphs = ['<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>'.htmlspecialchars($name).'</w:t></w:r></w:p>', $this->wp("الفئة المستهدفة: {$kit->audience}"), $this->wp('مدة الجلسة: '.rtrim(rtrim(number_format($kit->duration_hours, 1), '0'), '.').' ساعات')];
        foreach ($sections as $i => $section) {
            $paragraphs[] = '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>'.htmlspecialchars(($i + 1).'. '.$section).'</w:t></w:r></w:p>';
            $paragraphs[] = $this->wp('يبدأ المدرب هذا الجزء بسؤال افتتاحي، ثم يعرض المحتوى، ويخصص وقتاً للنقاش والتطبيق. الزمن المقترح لهذا الجزء من 20 إلى 30 دقيقة.');
            $paragraphs[] = $this->wp('نصيحة للمدرب: اربط المحتوى بمواقف حقيقية من ميدان العمل، وشجّع المشاركين على مشاركة تجاربهم.');
        }

        return $this->storeDocx($kit, $user, $name, $category, implode('', $paragraphs));
    }

    private function wp(string $text): string
    {
        return '<w:p><w:pPr><w:bidi/></w:pPr><w:r><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r></w:p>';
    }

    private function storeDocx(TrainingKit $kit, User $user, string $name, string $category, string $body): KitFile
    {
        $zip = new ZipArchive;
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $this->storeFile($kit, $user, $name, $category, 'document', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $bytes);
    }

    private function handout(TrainingKit $kit, User $user, string $name, string $category): KitFile
    {
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true, 'directionality' => 'rtl']);
        $items = collect($kit->objectives)->map(fn ($o) => '<li style="margin:6px 0">'.e($o).'</li>')->implode('');
        $mpdf->WriteHTML('<html dir="rtl"><body style="font-size:13pt;color:#1a1a1a"><h1 style="color:#8A1538">'.e($name).'</h1><p style="color:#6b7280">'.e($kit->title_ar).'</p><h2 style="color:#A29475">الأهداف التدريبية</h2><ul>'.$items.'</ul>'
            .'<h2 style="color:#A29475">ورقة العمل</h2><p>1. اكتب موقفاً من عملك مرتبطاً بموضوع الحقيبة: ..................................................</p><p>2. ما الخطوة التي ستطبقها الأسبوع القادم؟ ..................................................</p><p>3. ما التحدي المتوقع وكيف ستتعامل معه؟ ..................................................</p></body></html>');

        return $this->storeFile($kit, $user, $name, $category, 'pdf', 'pdf', 'application/pdf', $mpdf->Output('', 'S'));
    }

    private function storeFile(TrainingKit $kit, User $user, string $name, string $category, string $kind, string $ext, string $mime, string $bytes): KitFile
    {
        $path = app(FileStorage::class)->put('documents', "kits/{$kit->id}/files/".Str::uuid().".{$ext}", $bytes, $mime);
        $file = KitFile::create(['kit_id' => $kit->id, 'name' => $name, 'original_name' => Str::slug($name, '_').".{$ext}", 'kind' => $kind, 'category' => $category, 'source' => 'upload', 'mime' => $mime, 'size' => strlen($bytes),
            'storage_path' => $path, 'uploaded_by' => $user->id, 'updated_by' => $user->id, 'sort_order' => KitFile::where('kit_id', $kit->id)->count() + 1]);
        KitLog::record($kit, $user, 'file_uploaded', 'file', $file->id, ['name' => $name, 'kind' => $kind]);

        return $file;
    }

    /** A QA comment pinned to a slide, optionally with the developer's reply. */
    private function thread(TrainingKit $kit, KitFile $deck, User $qa, User $dev, ?string $slideId, string $body, string $category, string $severity, string $status, ?string $reply, int $round, int $daysAgo, ?int $x = null, ?int $y = null, ?string $fileId = null): void
    {
        $slides = collect($deck->content['slides']);
        $index = $slideId ? $slides->search(fn ($s) => $s['id'] === $slideId) : false;
        $comment = KitComment::create([
            'kit_id' => $kit->id, 'file_id' => $fileId ?? $deck->id, 'author_id' => $qa->id, 'body' => $body, 'category' => $category, 'severity' => $severity, 'status' => $status,
            'assignee_id' => $dev->id, 'review_round' => $round, 'file_version' => 1,
            'anchor' => $slideId ? ($x !== null ? ['type' => 'point', 'slide_id' => $slideId, 'slide_index' => $index, 'x' => $x, 'y' => $y] : ['type' => 'slide', 'slide_id' => $slideId, 'slide_index' => $index]) : ['type' => 'file'],
            'resolved_by' => $status === 'resolved' ? $qa->id : null, 'resolved_at' => $status === 'resolved' ? now()->addDays($daysAgo + 1) : null,
        ]);
        $comment->forceFill(['created_at' => now()->addDays($daysAgo)->subHours(3), 'updated_at' => now()->addDays($daysAgo)])->save();
        KitLog::record($kit, $qa, 'commented', 'comment', $comment->id, ['severity' => $severity]);

        if ($reply) {
            $r = KitComment::create(['kit_id' => $kit->id, 'file_id' => $comment->file_id, 'parent_id' => $comment->id, 'author_id' => $dev->id, 'body' => $reply, 'category' => $category, 'severity' => $severity, 'status' => 'open', 'review_round' => $round]);
            $r->forceFill(['created_at' => now()->addDays($daysAgo)->addHours(2)])->save();
        }
    }
}
