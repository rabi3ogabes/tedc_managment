<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Employee;
use App\Models\Program;
use App\Models\PushLog;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Push\FcmClient;
use App\Services\Push\PushDispatcher;
use App\Services\Push\PushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Settings → Notifications: Firebase credentials, pushed categories, connection check and test sends. */
class PushSettingsController extends Controller
{
    public function __construct(private readonly PushSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => $this->settings->forAdmin(),
            'categories' => PushSettings::CATEGORIES,
            'stats' => $this->stats(),
            'logs' => PushLog::latest()->limit(25)->get(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'service_account_json' => ['nullable', 'string', 'max:20000'],
            'remove_service_account' => ['sometimes', 'boolean'],
            'client' => ['sometimes', 'array'],
            'client.api_key' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'client.app_id' => ['nullable', 'string', 'max:100', 'regex:/^[0-9]+:[0-9]+:[a-z]+:[0-9a-f]+$/'],
            'client.messaging_sender_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'client.project_id' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            'client.storage_bucket' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9.\-_]+$/'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
            'android' => ['sometimes', 'array'],
            'android.channel_name_ar' => ['sometimes', 'string', 'max:60'],
            'android.channel_name_en' => ['sometimes', 'string', 'max:60'],
            'android.color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json(['data' => [
            'settings' => $this->settings->update($data, $request->user()),
            'categories' => PushSettings::CATEGORIES,
            'stats' => $this->stats(),
            'logs' => PushLog::latest()->limit(25)->get(),
        ]]);
    }

    /** Signs in to Google with the stored service account. */
    public function verify(FcmClient $fcm): JsonResponse
    {
        try {
            $fcm->accessToken(fresh: true);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['service_account_json' => $e->getMessage()]);
        }

        return response()->json(['data' => ['ok' => true, 'project_id' => $this->settings->serviceAccount()['project_id'] ?? null]]);
    }

    /** People and groups a test notification can be sent to, with how many of their devices are registered. */
    public function recipients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $devices = DeviceToken::query()->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        $people = $q === '' ? collect() : User::query()->with('roles:id,slug,name_ar,name_en')
            ->where(fn ($w) => $w->whereLike('name', "%{$q}%")->orWhereLike('name_ar', "%{$q}%")->orWhereLike('email', "%{$q}%"))
            ->orderBy('name')->limit(12)->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email, 'roles' => $u->roles->map(fn (Role $r) => $this->roleName($r))->all(), 'devices' => (int) ($devices[$u->id] ?? 0)]);

        $count = fn ($userIds) => ['users' => $userIds->count(), 'devices' => (int) $userIds->sum(fn ($id) => $devices[$id] ?? 0)];

        return response()->json(['data' => [
            'people' => $people->values(),
            'groups' => [
                'role' => Role::with('users:id')->orderBy('name_en')->get()->map(fn (Role $r) => ['id' => $r->slug, 'name' => $this->roleName($r)] + $count($r->users->pluck('id')))->filter(fn ($g) => $g['users'] > 0)->values(),
                'school' => School::orderBy('name_en')->limit(200)->get()->map(fn (School $s) => ['id' => $s->id, 'name' => $s->translate('name')] + $count($this->userIds('school', $s->id)))->filter(fn ($g) => $g['users'] > 0)->values(),
                'program' => Program::whereNotIn('status', [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED])->orderByDesc('created_at')->limit(100)->get()
                    ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->translate('title')] + $count($this->userIds('program', $p->id)))->filter(fn ($g) => $g['users'] > 0)->values(),
            ],
        ]]);
    }

    /** Sends a test notification to the administrator, one person, a group (role, school, program participants) or everyone. */
    public function test(Request $request, PushDispatcher $push): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['sometimes', Rule::in(['me', 'all', 'user', 'group'])],
            'user_id' => ['required_if:audience,user', 'nullable', 'uuid'],
            'group_type' => ['required_if:audience,group', 'nullable', Rule::in(['role', 'school', 'program'])],
            'group_id' => ['required_if:audience,group', 'nullable', 'string', 'max:64'],
            'title_ar' => ['nullable', 'string', 'max:120'],
            'title_en' => ['nullable', 'string', 'max:120'],
            'body_ar' => ['nullable', 'string', 'max:300'],
            'body_en' => ['nullable', 'string', 'max:300'],
        ]);

        if (! $this->settings->isReady()) {
            throw ValidationException::withMessages(['enabled' => __('push.not_ready')]);
        }

        $audience = $data['audience'] ?? 'me';
        $userIds = match ($audience) {
            'all' => DeviceToken::query()->distinct()->pluck('user_id'),
            'user' => collect([$data['user_id']]),
            'group' => $this->userIds($data['group_type'], $data['group_id']),
            default => collect([$request->user()->id]),
        };
        $withDevices = DeviceToken::whereIn('user_id', $userIds)->distinct()->pluck('user_id');

        if ($withDevices->isEmpty()) {
            throw ValidationException::withMessages(['audience' => in_array($audience, ['user', 'group'], true)
                ? trans_choice('push.no_devices_targeted', $userIds->count(), ['count' => $userIds->count()])
                : __('push.no_devices')]);
        }

        $log = $push->dispatch(
            $withDevices,
            'test',
            ['ar' => $data['title_ar'] ?? 'إشعار تجريبي', 'en' => $data['title_en'] ?? 'Test notification'],
            ['ar' => $data['body_ar'] ?? 'الإشعارات تعمل بنجاح ✓', 'en' => $data['body_en'] ?? 'Push notifications are working ✓'],
            ['route' => '/notifications'],
            force: true,
            triggeredBy: $request->user()->id,
        );

        return response()->json(['data' => $log->toArray() + ['targeted' => $userIds->count(), 'with_devices' => $withDevices->count()]]);
    }

    private function roleName(Role $role): string
    {
        return app()->getLocale() === 'en' ? $role->name_en : $role->name_ar;
    }

    /** @return Collection<int, string> user ids of a group */
    private function userIds(string $type, string $id): Collection
    {
        return match ($type) {
            'role' => User::whereHas('roles', fn ($q) => $q->where('slug', $id))->pluck('id'),
            'school' => Employee::where('school_id', $id)->whereNotNull('user_id')->pluck('user_id'),
            'program' => Employee::whereIn('id', Registration::where('program_id', $id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->select('employee_id'))->whereNotNull('user_id')->pluck('user_id'),
            default => collect(),
        };
    }

    private function stats(): array
    {
        $since = now()->subDays(7);

        return [
            'devices' => DeviceToken::count(),
            'users' => DeviceToken::distinct()->count('user_id'),
            'by_platform' => DeviceToken::selectRaw('platform, count(*) as total')->groupBy('platform')->pluck('total', 'platform'),
            'delivered_7d' => (int) PushLog::where('created_at', '>=', $since)->sum('delivered'),
            'failed_7d' => (int) PushLog::where('created_at', '>=', $since)->sum('failed'),
        ];
    }
}
