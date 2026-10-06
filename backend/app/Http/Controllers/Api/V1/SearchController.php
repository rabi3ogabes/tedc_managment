<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Trainer;
use App\Models\TrainingKit;
use App\Services\Kits\KitAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global quick search (Ctrl/⌘K): programs, people, trainers, kits, certificates and news — each group only for what the
 * user's active role may see, and people only inside the role's scope. Navigation targets ("functions") are matched in the app.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:80'], 'types' => ['nullable', 'string', 'max:120']]);
        $q = trim($data['q']);
        $user = $this->user();
        if (mb_strlen($q) < 2 || ! $user->hasPermission('search.global')) {
            return response()->json(['data' => []]);
        }
        $want = isset($data['types']) && $data['types'] !== '' ? explode(',', $data['types']) : null;
        $wants = fn (string $type) => $want === null || in_array($type, $want, true);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $staff = $user->hasPermission('programs.view');
        $groups = [];

        if ($wants('programs')) {
            $rows = Program::query()
                ->when(! $staff, fn ($w) => $w->whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN, Program::STATUS_IN_PROGRESS]))
                ->where(fn ($w) => $w->whereLike('title_ar', $like)->orWhereLike('title_en', $like)->orWhereLike('code', $like))
                ->orderBy('title_ar')->limit(self::LIMIT)->get();
            $groups[] = ['type' => 'programs', 'items' => $rows->map(fn (Program $p) => [
                'id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title'), 'subtitle' => $p->code, 'url' => $staff ? "/admin/programs/{$p->id}" : "/programs/{$p->code}",
            ])->all()];
        }
        if ($wants('people') && $user->hasPermission('employees.view')) {
            $scope = $this->scope();
            $rows = $scope->constrainEmployees(Employee::with(['user:id,name,name_ar', 'school:id,name_ar,name_en'])->where(fn ($w) => $w->whereLike('employee_no', $like)
                ->orWhereHas('user', fn ($u) => $u->whereLike('name', $like)->orWhereLike('name_ar', $like))))->limit(self::LIMIT)->get();
            $groups[] = ['type' => 'people', 'items' => $rows->map(fn (Employee $e) => [
                'id' => $e->id, 'title' => $e->user?->displayName() ?? $e->employee_no, 'subtitle' => trim($e->employee_no.' · '.($e->school?->translate('name') ?? ''), ' ·'), 'url' => "/admin/employees/{$e->id}",
            ])->all()];
        }
        if ($wants('trainers') && ($user->hasPermission('programs.view') || $user->hasPermission('trainers.manage'))) {
            $rows = Trainer::where(fn ($w) => $w->whereLike('name_ar', $like)->orWhereLike('name_en', $like))->limit(self::LIMIT)->get();
            $groups[] = ['type' => 'trainers', 'items' => $rows->map(fn (Trainer $t) => ['id' => $t->id, 'title' => $t->translate('name'), 'subtitle' => $t->organization, 'url' => '/admin/trainers'])->all()];
        }
        if ($wants('kits') && $user->hasPermission('kits.view')) {
            $rows = KitAccess::scope(TrainingKit::query(), $user)->where(fn ($w) => $w->whereLike('title_ar', $like)->orWhereLike('title_en', $like)->orWhereLike('code', $like))->limit(self::LIMIT)->get();
            $groups[] = ['type' => 'kits', 'items' => $rows->map(fn (TrainingKit $k) => ['id' => $k->id, 'code' => $k->code, 'title' => $k->translate('title'), 'subtitle' => $k->code, 'url' => '/admin/kits'])->all()];
        }
        if ($wants('certificates') && $user->hasPermission('certificates.view')) {
            $rows = $this->scope()->constrainThroughEmployee(Certificate::with('program:id,title_ar,title_en')->whereLike('certificate_no', $like))->limit(self::LIMIT)->get();
            $groups[] = ['type' => 'certificates', 'items' => $rows->map(fn (Certificate $c) => ['id' => $c->id, 'title' => $c->certificate_no, 'subtitle' => $c->program?->translate('title'), 'url' => '/admin/certificates?q='.urlencode($c->certificate_no)])->all()];
        }
        if ($wants('news')) {
            $rows = app(\App\Services\Communication\AnnouncementLifecycle::class)->live(Announcement::query())->where('is_public', true)
                ->where(fn ($w) => $w->whereLike('title_ar', $like)->orWhereLike('title_en', $like))->latest('published_at')->limit(self::LIMIT)->get();
            $groups[] = ['type' => 'news', 'items' => $rows->map(fn (Announcement $a) => ['id' => $a->id, 'title' => $a->translate('title'), 'subtitle' => $a->published_at?->toDateString(), 'url' => "/news/{$a->id}"])->all()];
        }

        return response()->json(['data' => array_values(array_filter($groups, fn ($g) => $g['items'] !== []))]);
    }
}
