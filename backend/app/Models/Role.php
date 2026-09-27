<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'name_en', 'name_ar', 'description', 'level', 'is_system'])]
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

    /** Roles that administer the training center as a whole. */
    public const CENTER_STAFF = [self::SUPER_ADMIN, self::CENTER_ADMIN, self::COORDINATOR];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
