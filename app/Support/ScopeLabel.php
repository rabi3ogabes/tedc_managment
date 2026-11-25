<?php

namespace App\Support;

use App\Models\Department;
use App\Models\RoleUser;
use App\Models\School;
use App\Models\SchoolGroup;

/** Names the scope of a role grant for people: "Ministry", the school, the school group or the department. */
class ScopeLabel
{
    /** @return array{ar: string, en: string} */
    public static function for(RoleUser $grant): array
    {
        $named = match ($grant->scope_type) {
            'school' => School::find($grant->scope_id),
            'school_group' => SchoolGroup::find($grant->scope_id),
            'department' => Department::find($grant->scope_id),
            default => null,
        };

        return $named
            ? ['ar' => $named->name_ar, 'en' => $named->name_en ?: $named->name_ar]
            : ['ar' => 'الوزارة', 'en' => 'Ministry'];
    }
}
