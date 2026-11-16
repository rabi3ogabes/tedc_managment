<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'name_en', 'name_ar', 'description', 'level', 'is_system', 'scope_levels', 'landing_route'])]
class Role extends Model
{
    use HasUuids;

    public const SUPER_ADMIN = 'super_admin';

    public const CENTER_ADMIN = 'center_admin';

    public const COORDINATOR = 'program_coordinator';

    public const TRAINER = 'trainer';

    public const SCHOOL_ADMIN = 'school_admin';

    public const EMPLOYEE = 'employee';

    public const SUPERVISOR = 'supervisor';

    public const EXECUTIVE = 'executive';

    /** معد الحقيبة - builds training kits. */
    public const KIT_DEVELOPER = 'kit_developer';

    /** فريق ضمان الجودة - reviews and signs off training kits. */
    public const QA_REVIEWER = 'qa_reviewer';

    /** رئيس قسم التدريب — assigns supervisors, grants attendance rights, approves kits. */
    public const TRAINING_HEAD = 'training_head';

    /** مسؤول التطوير المهني (النائب الأكاديمي) — the school's professional-development officer. */
    public const ACADEMIC_DEPUTY = 'academic_deputy';

    /** قيادات المركز وواضعو السياسات. */
    public const CENTER_LEADERSHIP = 'center_leadership';

    /** رئيس قسم التخطيط. */
    public const PLANNING_HEAD = 'planning_head';

    /** أخصائي التخطيط. */
    public const PLANNING_SPECIALIST = 'planning_specialist';

    /** مسؤول الدعم اللوجستي. */
    public const LOGISTICS_OFFICER = 'logistics_officer';

    /** المسؤول المالي. */
    public const FINANCE_OFFICER = 'finance_officer';

    /** Roles that administer the training center as a whole. */
    public const CENTER_STAFF = [self::SUPER_ADMIN, self::CENTER_ADMIN, self::COORDINATOR];

    protected $casts = ['is_system' => 'boolean', 'scope_levels' => 'array'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(RoleUser::class)->withTimestamps();
    }
}
