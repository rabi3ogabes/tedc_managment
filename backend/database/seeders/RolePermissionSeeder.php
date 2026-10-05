<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /** slug => [group, ar, en] */
    public const PERMISSIONS = [
        'dashboard.view' => ['dashboard', 'عرض لوحة التحكم', 'View dashboard'],
        'analytics.view' => ['analytics', 'عرض التحليلات', 'View analytics'],
        'analytics.executive' => ['analytics', 'لوحة الإدارة العليا', 'Executive dashboard'],
        'schools.view' => ['organization', 'عرض المدارس', 'View schools'],
        'schools.manage' => ['organization', 'إدارة المدارس', 'Manage schools'],
        'employees.view' => ['organization', 'عرض الموظفين', 'View employees'],
        'employees.manage' => ['organization', 'إدارة الموظفين', 'Manage employees'],
        'programs.view' => ['programs', 'عرض البرامج', 'View programs'],
        'rooms.manage' => ['programs', 'إدارة القاعات التدريبية', 'Manage training rooms'],
        'calendar.view' => ['programs', 'عرض التقويم التدريبي', 'View training calendar'],
        'calendar.manage' => ['programs', 'إدارة الإجازات وأيام الاختبارات', 'Manage vacations & exam days'],
        'calendar.approve' => ['programs', 'اعتماد التدريب في الأيام المغلقة', 'Approve training on closed days'],
        'programs.manage' => ['programs', 'إدارة البرامج والجلسات', 'Manage programs & sessions'],
        'materials.manage' => ['programs', 'إدارة المواد التدريبية', 'Manage training materials'],
        'trainers.manage' => ['programs', 'إدارة المدربين', 'Manage trainers'],
        'registrations.view' => ['registrations', 'عرض التسجيلات', 'View registrations'],
        'registrations.manage' => ['registrations', 'اعتماد التسجيلات', 'Approve registrations'],
        'registrations.import' => ['registrations', 'الاستيراد الجماعي', 'Bulk import'],
        'nominations.center' => ['registrations', 'ترشيح مباشر من المركز', 'Training-center nomination'],
        'nominations.school' => ['registrations', 'ترشيح من المدرسة', 'School nomination'],
        'attendance.manage' => ['attendance', 'إدارة الحضور', 'Manage attendance'],
        'tasks.manage' => ['tasks', 'إدارة المهام', 'Manage tasks'],
        'tasks.review' => ['tasks', 'مراجعة المهام', 'Review submissions'],
        'certificates.view' => ['certificates', 'عرض الشهادات', 'View certificates'],
        'certificates.issue' => ['certificates', 'إصدار الشهادات', 'Issue certificates'],
        'certificates.revoke' => ['certificates', 'إلغاء الشهادات', 'Revoke certificates'],
        'impact.view' => ['impact', 'عرض قياس الأثر', 'View impact'],
        'impact.supervise' => ['impact', 'تقييم المشرف', 'Supervisor evaluation'],
        'needs.submit' => ['needs', 'رفع الاحتياجات التدريبية', 'Submit training needs'],
        'needs.view' => ['needs', 'عرض الاحتياجات التدريبية', 'View training needs'],
        'needs.manage' => ['needs', 'إدارة الاحتياجات التدريبية', 'Manage training needs'],
        'announcements.manage' => ['communication', 'مركز التواصل', 'Communication center'],
        'ai.assistant' => ['ai', 'المساعد الذكي', 'AI assistant'],
        'reports.view' => ['reports', 'التقارير', 'Reports'],
        'users.manage' => ['security', 'إدارة المستخدمين', 'Manage users'],
        'roles.manage' => ['security', 'إدارة الأدوار والصلاحيات', 'Manage roles & permissions'],
        'audit.view' => ['security', 'سجل التدقيق', 'Audit log'],
        'kits.view' => ['kits', 'عرض الحقائب التدريبية', 'View training kits'],
        'kits.manage' => ['kits', 'إعداد الحقائب وتحرير ملفاتها', 'Build training kits & edit their files'],
        'kits.generate' => ['kits', 'التوليد الذكي للعروض والصور والفيديو', 'AI generation of decks, images and videos'],
        'kits.review' => ['kits', 'مراجعة الحقائب (ضمان الجودة)', 'Review training kits (quality assurance)'],
        'kits.publish' => ['kits', 'نشر الحقائب وأرشفتها', 'Publish & archive training kits'],
        'settings.manage' => ['settings', 'الهوية البصرية والمظهر', 'Brand & appearance'],
        'scopes.manage' => ['security', 'إدارة مجموعات المدارس والنطاقات', 'Manage school groups & scopes'],
        'roles.create' => ['security', 'إنشاء الأدوار المخصصة', 'Create custom roles'],
        'program_grants.manage' => ['programs', 'منح صلاحيات البرنامج (الحضور والإشعارات)', 'Grant program rights (attendance, notifications)'],
        'trainers.assign' => ['programs', 'ترشيح المدربين للمجموعات', 'Propose trainers for groups'],
        'trainers.approve' => ['programs', 'اعتماد تكليف المدربين', 'Approve trainer assignments'],
        'rooms.book' => ['programs', 'حجز القاعات لأنشطة غير تدريبية', 'Book rooms for non-training use'],
        'logistics.manage' => ['programs', 'إدارة الطلبات اللوجستية', 'Manage logistics requests'],
        'plans.view' => ['planning', 'عرض الخطة التدريبية', 'View the training plan'],
        'plans.manage' => ['planning', 'إعداد الخطة التدريبية', 'Prepare the training plan'],
        'plans.approve' => ['planning', 'اعتماد الخطة التدريبية', 'Approve the training plan'],
        'instruments.approve' => ['planning', 'اعتماد أدوات الحصر والتقييم', 'Approve needs and evaluation instruments'],
        'pd.approve' => ['planning', 'اعتماد سجلات التطوير المهني', 'Approve professional-development records'],
        'workshops.internal' => ['programs', 'ورش العمل الداخلية للمدرسة', 'School internal workshops'],
        'search.global' => ['general', 'البحث الشامل', 'Global search'],
        'groups.manage' => ['programs', 'إدارة المجموعات التدريبية', 'Manage training groups'],
        'groups.status' => ['programs', 'تغيير حالة المجموعات التدريبية', 'Change the status of training groups'],
        'banks.manage' => ['programs', 'إدارة بنوك الأسئلة', 'Manage question banks'],
        'assessments.manage' => ['programs', 'إدارة الاختبارات', 'Manage assessments'],
        'assessments.grade' => ['programs', 'تصحيح الاختبارات يدوياً', 'Grade assessments manually'],
        'assessments.invigilate' => ['programs', 'مراقبة الاختبارات', 'Invigilate assessments'],
        'assessments.analytics' => ['programs', 'تحليلات الاختبارات', 'View assessment analytics'],
        'attendance.devices' => ['attendance', 'إدارة أجهزة البصمة', 'Manage attendance devices'],
        'excuses.decide' => ['attendance', 'البتّ في أعذار الغياب', 'Decide absence excuses'],
        'leaves.manage' => ['attendance', 'تسجيل الاستئذان', 'Record leaves'],
        'seating.manage' => ['programs', 'إدارة مخططات الجلوس', 'Manage seating plans'],
        'places.manage' => ['programs', 'إدارة أماكن التدريب والمباني', 'Manage training places and buildings'],
        'seats.manage' => ['registrations', 'تخصيص مقاعد المجموعات', 'Allocate group seats'],
        'priority.manage' => ['registrations', 'إدارة قواعد أولوية القبول', 'Manage admission priority rules'],
        'registrations.approve_manager' => ['registrations', 'موافقة المدير المباشر على التسجيل', 'Approve registrations as direct manager'],
        'registrations.approve_center' => ['registrations', 'اعتماد التسجيل من مركز التدريب', 'Approve registrations as the centre'],
        'withdrawals.decide' => ['registrations', 'البتّ في طلبات الانسحاب', 'Decide withdrawal requests'],
        'withdrawals.policy' => ['registrations', 'إدارة سياسة الانسحاب', 'Manage the withdrawal policy'],
        'external_forms.manage' => ['registrations', 'إدارة نماذج التسجيل الخارجي', 'Manage external registration forms'],
        'external_requests.review' => ['registrations', 'مراجعة طلبات التسجيل الخارجي', 'Review external registration requests'],
        'competencies.manage' => ['needs', 'إدارة إطار الجدارات', 'Manage the competency framework'],
        'needs.cycles' => ['needs', 'إدارة دورة الاحتياجات والمقترحات', 'Manage the needs cycle and proposals'],
        'needs.propose' => ['needs', 'تقديم مقترحات البرامج التخصصية', 'Submit specialised program proposals'],
        'needs.request' => ['needs', 'رفع احتياجات مؤسسية للموظفين', 'Request programs for staff'],
        'needs.approve_individual' => ['needs', 'اعتماد الاحتياجات الفردية للموظفين', 'Approve individual needs of staff'],
        'performance.import' => ['needs', 'استيراد بيانات الأداء والملاحظات الصفية', 'Import performance and observation data'],
        'gaps.view' => ['needs', 'عرض تحليل الفجوات', 'View the gap analysis'],
        'trainers.respond' => ['programs', 'الرد على ترشيحات التدريب', 'Respond to training proposals'],
        'workshops.approve' => ['programs', 'اعتماد الورش الداخلية للمدارس', 'Approve school internal workshops'],
    ];

    /** Which scopes a role may be granted at (null = any). A role without Ministry here is limited to its school unless granted wider. */
    public const SCOPE_LEVELS = [
        Role::SCHOOL_ADMIN => ['school', 'school_group', 'department'],
        Role::ACADEMIC_DEPUTY => ['school', 'school_group', 'department'],
        Role::SUPERVISOR => null,
    ];

    /** Where each role lands after signing in or switching to it. */
    public const LANDING = [
        Role::SUPER_ADMIN => '/admin', Role::CENTER_ADMIN => '/admin', Role::COORDINATOR => '/admin', Role::TRAINING_HEAD => '/admin',
        Role::CENTER_LEADERSHIP => '/admin/executive', Role::EXECUTIVE => '/admin/executive', Role::PLANNING_HEAD => '/admin', Role::PLANNING_SPECIALIST => '/admin',
        Role::LOGISTICS_OFFICER => '/admin/rooms', Role::KIT_DEVELOPER => '/admin/kits', Role::QA_REVIEWER => '/admin/kits', Role::TRAINER => '/admin/programs',
        Role::SCHOOL_ADMIN => '/admin', Role::ACADEMIC_DEPUTY => '/admin', Role::SUPERVISOR => '/portal', Role::EMPLOYEE => '/portal',
    ];

    public const ROLES = [
        Role::SUPER_ADMIN => ['مدير النظام', 'Super Admin', 100, ['*']],
        Role::CENTER_ADMIN => ['مدير مركز التدريب', 'Training Center Admin', 90, ['*', '-roles.manage', '-roles.create']],
        Role::COORDINATOR => ['مشرف التدريب', 'Training Supervisor', 70, [
            'dashboard.view', 'analytics.view', 'schools.view', 'employees.view', 'programs.view', 'programs.manage', 'materials.manage',
            'trainers.manage', 'rooms.manage', 'calendar.view', 'registrations.view', 'registrations.manage', 'registrations.import', 'nominations.center', 'attendance.manage',
            'tasks.manage', 'tasks.review', 'certificates.view', 'certificates.issue', 'impact.view', 'needs.view', 'needs.manage',
            'announcements.manage', 'ai.assistant', 'reports.view', 'kits.view', 'kits.manage', 'kits.generate', 'kits.review', 'kits.publish',
            'plans.view', 'search.global', 'groups.manage', 'groups.status', 'workshops.approve', 'trainers.assign',
            'needs.cycles', 'competencies.manage', 'gaps.view', 'performance.import',
            'seats.manage', 'priority.manage', 'registrations.approve_center', 'withdrawals.decide', 'withdrawals.policy', 'external_forms.manage', 'external_requests.review',
            'attendance.devices', 'excuses.decide', 'leaves.manage', 'seating.manage', 'places.manage',
            'banks.manage', 'assessments.manage', 'assessments.grade', 'assessments.invigilate', 'assessments.analytics',
        ]],
        Role::TRAINER => ['مدرب', 'Trainer', 50, ['programs.view', 'materials.manage', 'attendance.manage', 'tasks.manage', 'tasks.review', 'trainers.respond', 'banks.manage', 'assessments.manage', 'assessments.grade', 'assessments.invigilate', 'search.global']],
        Role::SCHOOL_ADMIN => ['مدير مدرسة', 'School Admin', 40, [
            'dashboard.view', 'schools.view', 'employees.view', 'registrations.view', 'registrations.import', 'nominations.school',
            'certificates.view', 'impact.view', 'needs.submit', 'needs.view', 'reports.view', 'search.global',
            'needs.propose', 'needs.request', 'needs.approve_individual', 'registrations.approve_manager',
        ]],
        Role::SUPERVISOR => ['مشرف', 'Supervisor', 30, ['employees.view', 'impact.supervise', 'impact.view', 'needs.request', 'needs.approve_individual', 'registrations.approve_manager', 'search.global']],
        Role::EXECUTIVE => ['الإدارة العليا', 'Executive', 80, [
            'dashboard.view', 'analytics.view', 'analytics.executive', 'schools.view', 'programs.view', 'calendar.view', 'certificates.view',
            'impact.view', 'needs.view', 'ai.assistant', 'reports.view', 'plans.view', 'search.global',
        ]],
        Role::KIT_DEVELOPER => ['معد الحقيبة', 'Kit Developer', 60, ['programs.view', 'kits.view', 'kits.manage', 'kits.generate', 'banks.manage', 'search.global']],
        Role::QA_REVIEWER => ['فريق ضمان الجودة', 'Quality Assurance', 55, ['programs.view', 'kits.view', 'kits.manage', 'kits.review', 'search.global']],
        Role::EMPLOYEE => ['موظف', 'Employee', 10, ['search.global']],
        Role::TRAINING_HEAD => ['رئيس قسم التدريب', 'Head of Training', 75, [
            'dashboard.view', 'analytics.view', 'schools.view', 'employees.view', 'programs.view', 'programs.manage', 'materials.manage', 'trainers.manage', 'trainers.assign', 'rooms.manage',
            'calendar.view', 'registrations.view', 'registrations.manage', 'attendance.manage', 'tasks.review', 'certificates.view', 'impact.view', 'needs.view', 'announcements.manage',
            'reports.view', 'kits.view', 'kits.manage', 'kits.review', 'kits.publish', 'program_grants.manage', 'plans.view', 'search.global',
            'groups.manage', 'groups.status', 'workshops.approve', 'gaps.view', 'needs.cycles',
            'seats.manage', 'priority.manage', 'registrations.approve_center', 'withdrawals.decide', 'withdrawals.policy', 'external_forms.manage', 'external_requests.review',
            'attendance.devices', 'excuses.decide', 'leaves.manage', 'seating.manage', 'places.manage',
            'banks.manage', 'assessments.manage', 'assessments.grade', 'assessments.invigilate', 'assessments.analytics',
        ]],
        Role::ACADEMIC_DEPUTY => ['مسؤول التطوير المهني (النائب الأكاديمي)', 'Professional Development Officer (Academic Deputy)', 45, [
            'dashboard.view', 'schools.view', 'employees.view', 'programs.view', 'calendar.view', 'registrations.view', 'registrations.import', 'nominations.school', 'certificates.view',
            'impact.view', 'needs.submit', 'needs.view', 'reports.view', 'pd.approve', 'workshops.internal', 'plans.view', 'search.global',
            'needs.propose', 'needs.request', 'needs.approve_individual', 'registrations.approve_manager',
        ]],
        Role::CENTER_LEADERSHIP => ['قيادات المركز وواضعو السياسات', 'Centre Leadership & Policy Makers', 82, [
            'dashboard.view', 'analytics.view', 'analytics.executive', 'schools.view', 'programs.view', 'calendar.view', 'certificates.view', 'impact.view', 'needs.view', 'ai.assistant',
            'reports.view', 'trainers.approve', 'plans.view', 'plans.approve', 'gaps.view', 'search.global',
        ]],
        Role::PLANNING_HEAD => ['رئيس قسم التخطيط', 'Head of Planning', 66, [
            'dashboard.view', 'analytics.view', 'schools.view', 'employees.view', 'programs.view', 'calendar.view', 'impact.view', 'needs.view', 'needs.manage', 'ai.assistant', 'reports.view',
            'plans.view', 'plans.manage', 'plans.approve', 'instruments.approve', 'search.global',
            'needs.cycles', 'competencies.manage', 'gaps.view', 'performance.import',
        ]],
        Role::PLANNING_SPECIALIST => ['أخصائي التخطيط', 'Planning Specialist', 58, [
            'dashboard.view', 'schools.view', 'programs.view', 'calendar.view', 'impact.view', 'needs.view', 'needs.manage', 'reports.view', 'plans.view', 'plans.manage', 'search.global',
            'needs.cycles', 'competencies.manage', 'gaps.view',
        ]],
        Role::LOGISTICS_OFFICER => ['مسؤول الدعم اللوجستي', 'Logistics Support Officer', 52, [
            'dashboard.view', 'programs.view', 'calendar.view', 'rooms.manage', 'rooms.book', 'logistics.manage', 'search.global',
        ]],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$group, $ar, $en]) {
            Permission::updateOrCreate(['slug' => $slug], ['group' => $group, 'name_ar' => $ar, 'name_en' => $en]);
        }
        $all = Permission::pluck('id', 'slug');

        foreach (self::ROLES as $slug => [$ar, $en, $level, $grants]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name_ar' => $ar, 'name_en' => $en, 'level' => $level, 'is_system' => true, 'scope_levels' => self::SCOPE_LEVELS[$slug] ?? ['ministry'], 'landing_route' => self::LANDING[$slug] ?? null]);

            $slugs = in_array('*', $grants, true) ? $all->keys()->all() : $grants;
            $slugs = array_diff($slugs, array_map(fn ($g) => ltrim($g, '-'), array_filter($grants, fn ($g) => str_starts_with($g, '-'))));

            $role->permissions()->sync($all->only($slugs)->values());
        }
    }

    /**
     * For a database that already exists: adds the permissions and roles that are new in this release and gives system
     * roles the permissions they lack — it never removes a permission an administrator granted or revoked by hand.
     */
    public function additive(): void
    {
        foreach (self::PERMISSIONS as $slug => [$group, $ar, $en]) {
            Permission::firstOrCreate(['slug' => $slug], ['group' => $group, 'name_ar' => $ar, 'name_en' => $en]);
        }
        $all = Permission::pluck('id', 'slug');

        foreach (self::ROLES as $slug => [$ar, $en, $level, $grants]) {
            $role = Role::where('slug', $slug)->first();
            $created = ! $role;
            $role ??= Role::create(['slug' => $slug, 'name_ar' => $ar, 'name_en' => $en, 'level' => $level, 'is_system' => true]);
            $role->fill(['is_system' => true, 'level' => $level, 'scope_levels' => $role->scope_levels ?? (self::SCOPE_LEVELS[$slug] ?? ['ministry']), 'landing_route' => $role->landing_route ?? (self::LANDING[$slug] ?? null)]);
            if ($created || $slug === Role::COORDINATOR) {
                $role->fill(['name_ar' => $ar, 'name_en' => $en]);   // new roles, and the renamed coordinator
            }
            $role->save();

            $slugs = in_array('*', $grants, true) ? $all->keys()->all() : $grants;
            $slugs = array_diff($slugs, array_map(fn ($g) => ltrim($g, '-'), array_filter($grants, fn ($g) => str_starts_with($g, '-'))));
            // Only the permissions introduced in this release are attached to existing roles; a new role gets its whole set.
            $role->permissions()->syncWithoutDetaching($all->only($created ? $slugs : array_intersect($slugs, self::ADDITIVE))->values());
        }
    }

    /**
     * Permissions introduced by the RFP phases, so a deploy attaches them to existing roles without touching the rest.
     * Every phase appends its new permissions here.
     */
    private const ADDITIVE = [
        // Phase 01
        'scopes.manage', 'roles.create', 'program_grants.manage', 'trainers.assign', 'trainers.approve', 'rooms.book', 'logistics.manage', 'plans.view', 'plans.manage', 'plans.approve', 'instruments.approve', 'pd.approve', 'workshops.internal', 'search.global',
        // Phase 02
        'groups.manage', 'groups.status', 'workshops.approve', 'trainers.respond',
        // Phase 03
        'competencies.manage', 'needs.cycles', 'needs.propose', 'needs.request', 'needs.approve_individual', 'performance.import', 'gaps.view',
        // Phase 04
        'seats.manage', 'priority.manage', 'registrations.approve_manager', 'registrations.approve_center', 'withdrawals.decide', 'withdrawals.policy', 'external_forms.manage', 'external_requests.review',
        // Phase 05
        'attendance.devices', 'excuses.decide', 'leaves.manage', 'seating.manage', 'places.manage',
        // Phase 06
        'banks.manage', 'assessments.manage', 'assessments.grade', 'assessments.invigilate', 'assessments.analytics',
    ];
}
