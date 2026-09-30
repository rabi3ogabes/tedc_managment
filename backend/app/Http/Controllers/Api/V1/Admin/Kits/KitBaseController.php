<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Http\Controllers\Controller;
use App\Models\TrainingKit;
use App\Services\Kits\KitAccess;

abstract class KitBaseController extends Controller
{
    protected function viewable(TrainingKit $kit): TrainingKit
    {
        abort_unless(KitAccess::canView($this->user(), $kit), 403, __('auth.forbidden'));

        return $kit;
    }

    protected function editable(TrainingKit $kit): TrainingKit
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canEditContent($this->user(), $kit), 403, in_array($kit->status, TrainingKit::EDITABLE, true) ? __('auth.forbidden') : __('messages.kit.locked'));

        return $kit;
    }

    protected function manageable(TrainingKit $kit): TrainingKit
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canManage($this->user(), $kit), 403, in_array($kit->status, TrainingKit::EDITABLE, true) ? __('auth.forbidden') : __('messages.kit.locked'));

        return $kit;
    }
}
