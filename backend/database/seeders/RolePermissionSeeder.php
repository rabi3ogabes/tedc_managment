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
    ];

    public const ROLES = [
        Role::SUPER_ADMIN => ['مدير النظام', 'Super Admin', 100, ['*']],
        Role::CENTER_ADMIN => ['مدير مركز التدريب', 'Training Center Admin', 90, ['*', '-roles.manage']],
        Role::COORDINATOR => ['منسق البرامج', 'Program Coordinator', 70, [
            'dashboard.view', 'analytics.view', 'schools.view', 'employees.view', 'programs.view', 'programs.manage', 'materials.manage',
            'trainers.manage', 'rooms.manage', 'calendar.view', 'registrations.view', 'registrations.manage', 'registrations.import', 'nominations.center', 'attendance.manage',
            'tasks.manage', 'tasks.review', 'certificates.view', 'certificates.issue', 'impact.view', 'needs.view', 'needs.manage',
            'announcements.manage', 'ai.assistant', 'reports.view', 'kits.view', 'kits.manage', 'kits.generate', 'kits.review', 'kits.publish',
        ]],
        Role::TRAINER => ['مدرب', 'Trainer', 50, ['programs.view', 'materials.manage', 'attendance.manage', 'tasks.manage', 'tasks.review']],
        Role::SCHOOL_ADMIN => ['مدير مدرسة', 'School Admin', 40, [
            'dashboard.view', 'schools.view', 'employees.view', 'registrations.view', 'registrations.import', 'nominations.school',
            'certificates.view', 'impact.view', 'needs.submit', 'needs.view', 'reports.view',
        ]],
        Role::SUPERVISOR => ['مشرف', 'Supervisor', 30, ['employees.view', 'impact.supervise', 'impact.view']],
        Role::EXECUTIVE => ['الإدارة العليا', 'Executive', 80, [
            'dashboard.view', 'analytics.view', 'analytics.executive', 'schools.view', 'programs.view', 'calendar.view', 'certificates.view',
            'impact.view', 'needs.view', 'ai.assistant', 'reports.view',
        ]],
        Role::KIT_DEVELOPER => ['معد الحقيبة', 'Kit Developer', 60, ['programs.view', 'kits.view', 'kits.manage', 'kits.generate']],
        Role::QA_REVIEWER => ['فريق ضمان الجودة', 'Quality Assurance', 55, ['programs.view', 'kits.view', 'kits.manage', 'kits.review']],
        Role::EMPLOYEE => ['موظف', 'Employee', 10, []],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$group, $ar, $en]) {
            Permission::updateOrCreate(['slug' => $slug], ['group' => $group, 'name_ar' => $ar, 'name_en' => $en]);
        }
        $all = Permission::pluck('id', 'slug');

        foreach (self::ROLES as $slug => [$ar, $en, $level, $grants]) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name_ar' => $ar, 'name_en' => $en, 'level' => $level, 'is_system' => true]);

            $slugs = in_array('*', $grants, true) ? $all->keys()->all() : $grants;
            $slugs = array_diff($slugs, array_map(fn ($g) => ltrim($g, '-'), array_filter($grants, fn ($g) => str_starts_with($g, '-'))));

            $role->permissions()->sync($all->only($slugs)->values());
        }
    }
}
