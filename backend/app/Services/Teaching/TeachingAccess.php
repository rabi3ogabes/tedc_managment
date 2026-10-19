<?php

namespace App\Services\Teaching;

use App\Models\GroupTrainer;
use App\Models\KitAccessEvent;
use App\Models\KitAsset;
use App\Models\KitFile;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\Kits\KitAccess;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What a trainer may see of the training kits: the approved and published kits attached to the programmes they teach — on the
 * programme, on one of its sessions or by an approved group assignment — and only through the protected reader and presenter
 * (nothing here hands out a file address or a download). Centre staff can open any attached kit to see what a trainer sees.
 */
class TeachingAccess
{
    public function __construct(private readonly KitProtection $protection, private readonly NotificationService $notifications) {}

    public function trainerOf(User $user): ?Trainer
    {
        return Trainer::where('user_id', $user->id)->first();
    }

    /** @return Collection<int, string> the programmes this user teaches (staff: every programme that has a kit) */
    public function programIds(User $user): Collection
    {
        if (KitAccess::isStaff($user)) {
            return Program::whereHas('kits')->whereNotIn('status', [Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED])->pluck('id');
        }
        $trainer = $this->trainerOf($user);
        if (! $trainer) {
            return collect();
        }
        $ids = $trainer->programs()->pluck('programs.id')
            ->merge(ProgramSession::where('trainer_id', $trainer->id)->where('status', '!=', 'cancelled')->pluck('program_id'))
            ->merge(Program::whereHas('groups', fn ($g) => $g->whereIn('id', GroupTrainer::where('trainer_id', $trainer->id)->where('status', GroupTrainer::APPROVED)->pluck('group_id')))->pluck('id'));

        return Program::whereIn('id', $ids->unique()->values())->whereNotIn('status', [Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED])->pluck('id');
    }

    public function teaches(User $user, Program $program): bool
    {
        return $this->programIds($user)->contains($program->id);
    }

    /** Whether the user may open this kit in the reader: it is approved or published and attached to a programme they teach. */
    public function canView(User $user, TrainingKit $kit): bool
    {
        if (! in_array($kit->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true)) {
            return false;
        }

        return $kit->programs()->whereIn('programs.id', $this->programIds($user))->exists();
    }

    /** Whether this exact kit/programme pair is a legitimate context (used when the page says which programme it is teaching). */
    public function programFor(User $user, TrainingKit $kit, ?string $programId): ?Program
    {
        if (! $programId) {
            return null;
        }

        return $kit->programs()->whereKey($programId)->whereIn('programs.id', $this->programIds($user))->first();
    }

    /** What the reader needs to know of a file — never where it is stored. @return array<string, mixed> */
    public function fileRow(KitFile $f): array
    {
        $presentable = $f->isDeck() || $f->kind === 'pdf';

        return ['id' => $f->id, 'name' => $f->name, 'kind' => $f->kind, 'category' => $f->category, 'size' => $f->size, 'revision' => $f->revision, 'is_deck' => $f->isDeck(), 'presentable' => $presentable, 'mime' => $f->mime];
    }

    /** The deck with every picture, video and sound pointing at the protected asset route, not at a storage address. @return array<string, mixed> */
    public function deckFor(KitFile $file, TrainingKit $kit): array
    {
        $deck = (array) $file->content;
        $own = KitAsset::where('kit_id', $kit->id)->pluck('id')->flip();
        $path = fn (?string $id) => $id && $own->has($id) ? "/me/teaching/kits/{$kit->id}/assets/{$id}" : null;
        foreach ($deck['slides'] ?? [] as $i => $slide) {
            $deck['slides'][$i]['background']['src'] = $path($slide['background']['asset_id'] ?? null);
            foreach ($slide['elements'] ?? [] as $j => $el) {
                if (in_array($el['type'] ?? null, ['image', 'video', 'audio'], true)) {
                    $deck['slides'][$i]['elements'][$j]['src'] = $path($el['asset_id'] ?? null);
                }
            }
        }

        return $deck;
    }

    /** The people in a programme the presenter may call on: approved trainees, by first names only. @return list<array{id: string, name: string}> */
    public function roster(Program $program): array
    {
        return Registration::with('employee.user:id,name,name_ar')->where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->limit(300)->get()
            ->map(fn (Registration $r) => ['id' => $r->id, 'name' => (string) ($r->employee?->user?->displayName(app()->getLocale()) ?? '')])->filter(fn ($p) => $p['name'] !== '')->values()->all();
    }

    // ───────────────────────────── the trail

    /** @param array<string, mixed> $meta */
    public function log(User $user, TrainingKit $kit, string $kind, Request $request, ?KitFile $file = null, ?Program $program = null, ?string $sessionId = null, array $meta = []): ?KitAccessEvent
    {
        $settings = $this->protection->all();
        if (! $settings['log_events'] && $kind !== 'acknowledged') {
            return null;
        }
        $event = KitAccessEvent::create(['user_id' => $user->id, 'kit_id' => $kit->id, 'kit_file_id' => $file?->id, 'program_id' => $program?->id, 'session_id' => $sessionId, 'kind' => $kind,
            'meta' => $meta ?: null, 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255)]);
        if (in_array($kind, KitAccessEvent::ALERTS, true) && $settings['notify_admin']) {
            $this->alert($user, $kit, $file, $program, $kind, (int) $settings['notify_window_minutes']);
        }

        return $event;
    }

    public function acknowledged(User $user, TrainingKit $kit): bool
    {
        return KitAccessEvent::where('user_id', $user->id)->where('kit_id', $kit->id)->where('kind', 'acknowledged')->exists();
    }

    /** Tells the centre's staff once per trainer and kit in a window, so a burst of key presses is one alert. */
    private function alert(User $user, TrainingKit $kit, ?KitFile $file, ?Program $program, string $kind, int $minutes): void
    {
        if (! Cache::add("kit.alert.{$user->id}.{$kit->id}", 1, now()->addMinutes($minutes))) {
            return;
        }
        $what = ['capture_attempt' => ['ar' => 'تصوير للشاشة', 'en' => 'tried to capture the screen'], 'print_attempt' => ['ar' => 'طباعة أو حفظ', 'en' => 'tried to print or save'], 'tamper' => ['ar' => 'عبث بحماية المحتوى', 'en' => 'tried to tamper with the content protection']][$kind];
        $d = ['ar' => "رُصدت محاولة {$what['ar']} من {$user->displayName('ar')} أثناء عرض الحقيبة «{$kit->title_ar}»".($file ? " (الملف: {$file->name})" : '').($program ? " في برنامج «{$program->title_ar}»" : '').'. أُخفي المحتوى وسُجّلت المحاولة.',
            'en' => "{$user->displayName('en')} {$what['en']} while viewing the kit \"".($kit->title_en ?: $kit->title_ar).'"'.($file ? " (file: {$file->name})" : '').($program ? ' in "'.($program->title_en ?: $program->title_ar).'"' : '').'. The content was blacked out and the attempt logged.'];
        $staff = User::whereHas('roles', fn ($q) => $q->whereIn('slug', \App\Models\Role::CENTER_STAFF))->where('status', 'active')->where('id', '!=', $user->id)->pluck('id');
        $this->notifications->broadcast($staff, 'kit.capture_attempt', ['ar' => 'محاولة تصوير حقيبة تدريبية', 'en' => 'Attempt to capture a training kit'], $d,
            ['kit_id' => $kit->id, 'program_id' => $program?->id, 'route' => '/admin/kit-protection', 'detail_ar' => $d['ar'], 'detail_en' => $d['en']]);
    }
}
