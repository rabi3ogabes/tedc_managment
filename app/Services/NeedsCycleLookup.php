<?php

namespace App\Services;

use App\Models\NeedsCycle;

/** The id of the cycle that is open right now, if any. */
class NeedsCycleLookup
{
    public function currentId(): ?string
    {
        return NeedsCycle::where('status', NeedsCycle::OPEN)->orderByDesc('year')->value('id');
    }
}
