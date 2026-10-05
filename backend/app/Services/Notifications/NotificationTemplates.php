<?php

namespace App\Services\Notifications;

use App\Models\Certificate;
use App\Models\NotificationTemplate;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Task;
use App\Models\TrainingGroup;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ThemeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Template store + renderer. The wording given by the code that raises a notification is used as long as the
 * administrator has not changed the template; a changed template wins. A disabled template silences the action.
 */
class NotificationTemplates
{
    private const CACHE = 'notifications.templates';

    /** Makes sure every action of the catalogue has a template row. */
    public function ensure(): void
    {
        $existing = NotificationTemplate::pluck('event')->flip();
        foreach (NotificationCatalog::events() as $event => $d) {
            if (! $existing->has($event)) {
                NotificationTemplate::create(['event' => $event, 'is_system' => true] + collect($d)->only(['name_ar', 'name_en', 'title_ar', 'title_en', 'body_ar', 'body_en'])->all());
            }
        }
        Cache::forget(self::CACHE);
    }

    /** @return array<string, array<string, mixed>> keyed by event */
    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => NotificationTemplate::all()->keyBy('event')->map->toArray()->all());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE);
    }

    /** True when the administrator changed the wording of a system template (custom ones always count as changed). */
    public function isCustomised(array $tpl): bool
    {
        $default = NotificationCatalog::events()[$tpl['event']] ?? null;
        if (! $default) {
            return true;
        }

        return (bool) array_filter(['title_ar', 'title_en', 'body_ar', 'body_en'], fn ($k) => trim((string) ($tpl[$k] ?? '')) !== trim($default[$k]));
    }

    /**
     * @param  array{ar: string, en: string}  $title  wording given by the calling code
     * @param  array{ar: string, en: string}|null  $body
     * @return array{title: array{ar: string, en: string}, body: ?array{ar: string, en: string}, push: bool, template: ?array<string, mixed>}|null null = the action is switched off
     */
    public function compose(string $event, array $title, ?array $body, array $data, ?string $userId, bool $force = false): ?array
    {
        $tpl = $this->all()[$event] ?? null;
        if (! $tpl) {
            return ['title' => $title, 'body' => $body, 'push' => true, 'email' => true, 'sms' => true, 'template' => null];
        }
        if (! $tpl['enabled'] && ! $force) {
            return null;
        }
        if (! $this->isCustomised($tpl)) {
            return ['title' => $title, 'body' => $body, 'push' => (bool) $tpl['push'], 'email' => (bool) ($tpl['email'] ?? true), 'sms' => (bool) ($tpl['sms'] ?? true), 'template' => $tpl];
        }

        $vars = $this->variables($event, $data, $userId);

        return [
            'title' => ['ar' => $this->render($tpl['title_ar'], $vars['ar']), 'en' => $this->render($tpl['title_en'], $vars['en'])],
            'body' => ($tpl['body_ar'] ?? '') !== '' || ($tpl['body_en'] ?? '') !== ''
                ? ['ar' => $this->render((string) $tpl['body_ar'], $vars['ar']), 'en' => $this->render((string) $tpl['body_en'], $vars['en'])] : $body,
            'push' => (bool) $tpl['push'], 'email' => (bool) ($tpl['email'] ?? true), 'sms' => (bool) ($tpl['sms'] ?? true),
            'template' => $tpl,
        ];
    }

    public function render(string $text, array $vars): string
    {
        return trim((string) preg_replace('/\s{2,}/u', ' ', preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/u', fn ($m) => (string) ($vars[$m[1]] ?? ''), $text)));
    }

    /** Values of the placeholders for both languages, found from the ids the notification carries. @return array{ar: array<string, string>, en: array<string, string>} */
    public function variables(string $event, array $data, ?string $userId = null, ?Program $program = null): array
    {
        $registration = isset($data['registration_id']) ? Registration::with('program')->find($data['registration_id']) : null;
        $session = isset($data['session_id']) ? ProgramSession::find($data['session_id']) : null;
        $group = isset($data['group_id']) ? TrainingGroup::with('program')->find($data['group_id']) : null;
        $program ??= $registration?->program ?? $group?->program ?? (isset($data['program_id']) ? Program::find($data['program_id']) : ($session?->program));
        $plan = isset($data['plan_id']) ? TrainingPlan::find($data['plan_id']) : null;
        $task = isset($data['task_id']) ? Task::find($data['task_id']) : null;
        $certificate = isset($data['certificate_id']) ? Certificate::with('program')->find($data['certificate_id']) : null;
        $program ??= $certificate?->program;
        $user = $userId ? User::find($userId) : null;
        $center = rescue(fn () => app(ThemeService::class)->get()['identity']['center_name_ar'] ?? null, null, false);

        $status = ['ar' => ['pending' => 'قيد المراجعة', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'waitlisted' => 'في قائمة الانتظار', 'cancelled' => 'ملغى', 'completed' => 'مكتمل'],
            'en' => ['pending' => 'under review', 'approved' => 'approved', 'rejected' => 'rejected', 'waitlisted' => 'on the waiting list', 'cancelled' => 'cancelled', 'completed' => 'completed']];
        $suffix = str_contains($event, '.') ? substr($event, strrpos($event, '.') + 1) : '';
        $groupStatus = ['ar' => ['postponed' => 'مؤجلة', 'cancelled' => 'ملغاة', 'ongoing' => 'جارية', 'completed' => 'مكتملة', 'incomplete' => 'غير مكتملة', 'registration_open' => 'التسجيل مفتوح', 'planned' => 'مخطط لها'], 'en' => ['postponed' => 'postponed', 'cancelled' => 'cancelled', 'ongoing' => 'ongoing', 'completed' => 'completed', 'incomplete' => 'incomplete', 'registration_open' => 'open for registration', 'planned' => 'planned']];

        $out = [];
        foreach (['ar', 'en'] as $lang) {
            $when = $session?->starts_at ?? $program?->start_date;
            $out[$lang] = [
                'name' => $user ? ($lang === 'ar' ? ($user->name_ar ?: $user->name) : $user->name) : '',
                'program' => $program ? $program->{"title_{$lang}"} : ($task ? $task->{"title_{$lang}"} : ''),
                'program_code' => $program?->code ?? '',
                'session' => $session ? $session->{"title_{$lang}"} : '',
                'date' => isset($data['due_at']) ? Carbon::parse($data['due_at'])->locale($lang)->translatedFormat('j F Y') : ($when ? $when->copy()->locale($lang)->translatedFormat('j F Y') : ''),
                'time' => $session ? $session->starts_at->timezone(config('app.timezone'))->format('H:i') : '',
                'status' => $status[$lang][$data['decision'] ?? $suffix] ?? ($group ? ($groupStatus[$lang][$group->status] ?? '') : ''),
                'center' => (string) ($center ?? ''),
                'group' => $group ? $group->displayTitle($lang) : '',
                'reason' => (string) ($data['reason'] ?? ''),
                'plan' => $plan ? '«'.$plan->{"title_{$lang}"}.'» ('.$plan->year.')' : '',
            ];
        }

        return $out;
    }

    /** Variables of one program (campaigns sent by an administrator). */
    public function programVariables(Program $program): array
    {
        return $this->variables('', ['program_id' => $program->id], null, $program);
    }
}
