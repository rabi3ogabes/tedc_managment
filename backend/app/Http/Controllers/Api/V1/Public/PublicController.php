<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TrainerResource;
use App\Models\Announcement;
use App\Models\Certificate;
use App\Models\ContactMessage;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\School;
use App\Models\Trainer;
use App\Services\CertificateService;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Unauthenticated endpoints backing the public training-center website.
 */
class PublicController extends Controller
{
    public function home(): JsonResponse
    {
        $locale = app()->getLocale();

        $data = Cache::remember("public.home.{$locale}", 300, fn () => [
            'stats' => $this->statsPayload(),
            'featured_programs' => ProgramResource::collection($this->programQuery()->where('is_featured', true)->limit(6)->get())->resolve(),
            'upcoming_programs' => ProgramResource::collection($this->programQuery()
                ->whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN])
                ->where('start_date', '>=', today())->orderBy('start_date')->limit(6)->get())->resolve(),
            'categories' => ProgramCategory::withCount(['programs' => fn ($q) => $q->visible()])->get()
                ->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->translate('name'), 'icon' => $c->icon, 'color' => $c->color, 'programs' => $c->programs_count])->all(),
            'partners' => School::where('is_partner', true)->orderBy('name_ar')->limit(12)->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translate('name'), 'logo_url' => FileStorage::publicUrl($s->logo_path), 'stage' => $s->stage])->all(),
            'testimonials' => Evaluation::with(['employee.user', 'employee.jobTitle', 'employee.school', 'program'])
                ->where('allow_testimonial', true)->whereNotNull('comments')->where('satisfaction_score', '>=', 80)
                ->latest('submitted_at')->limit(6)->get()
                ->map(fn ($e) => [
                    'quote' => $e->comments,
                    'name' => $e->employee->user->displayName(),
                    'role' => $e->employee->jobTitle?->translate('name'),
                    'school' => $e->employee->school?->translate('name'),
                    'program' => $e->program->translate('title'),
                    'rating' => round($e->satisfaction_score / 20, 1),
                ])->all(),
            'news' => $this->newsQuery()->limit(3)->get()->map(fn ($a) => $this->newsItem($a))->all(),
        ]);

        return response()->json(['data' => $data]);
    }

    public function stats(): JsonResponse
    {
        return response()->json(['data' => Cache::remember('public.stats', 300, fn () => $this->statsPayload())]);
    }

    public function programs(Request $request): AnonymousResourceCollection
    {
        $programs = $this->programQuery()
            ->when($request->query('category'), fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($request->query('mode'), fn ($q, $mode) => $q->where('delivery_mode', $mode))
            ->when($request->query('level'), fn ($q, $level) => $q->where('level', $level))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('title_ar', 'like', "%{$term}%")->orWhere('title_en', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
            ->when($request->boolean('open'), fn ($q) => $q->whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN]))
            ->orderByRaw("case when status = 'registration_open' then 0 when status = 'published' then 1 else 2 end")
            ->orderBy('start_date')
            ->paginate($this->perPage($request, 12));

        return ProgramResource::collection($programs);
    }

    public function program(string $idOrCode): ProgramResource
    {
        $program = $this->programQuery()
            ->with(['sessions.trainer', 'sessions.room', 'targetGroups.jobTitle'])
            ->where(fn ($q) => Str::isUuid($idOrCode) ? $q->whereKey($idOrCode) : $q->where('code', $idOrCode))
            ->firstOrFail();

        return new ProgramResource($program);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ProgramCategory::orderBy('name_ar')->get()->map(fn ($c) => [
            'id' => $c->id, 'slug' => $c->slug, 'name' => $c->translate('name'), 'icon' => $c->icon, 'color' => $c->color,
        ])]);
    }

    public function trainers(): AnonymousResourceCollection
    {
        return TrainerResource::collection(Trainer::where('status', 'active')->withCount('programs')->orderByDesc('rating')->get());
    }

    public function calendar(Request $request): AnonymousResourceCollection
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? $from->copy()->addMonths(2)->endOfMonth();

        $sessions = ProgramSession::with(['program' => fn ($q) => $q->withCount(['registrations as seats_taken' => fn ($r) => $r->whereIn('status', Registration::SEAT_HOLDING)]), 'trainer', 'room'])
            ->whereHas('program', fn ($q) => $q->visible())
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->get();

        return SessionResource::collection($sessions)->additional([
            'programs' => $sessions->pluck('program')->unique('id')->values()->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->translate('title'),
                'seats_available' => max(0, $p->capacity - $p->seats_taken),
                'registration_open' => $p->isRegistrationOpen(),
            ]),
        ]);
    }

    public function news(Request $request): JsonResponse
    {
        $page = $this->newsQuery()->paginate($this->perPage($request, 9));

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => $this->newsItem($a)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function newsShow(string $id): JsonResponse
    {
        $item = $this->newsQuery()->findOrFail($id);

        return response()->json(['data' => $this->newsItem($item) + ['body' => $item->translate('body'), 'attachments' => $item->attachments ?? []]]);
    }

    private function newsItem(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'type' => $announcement->type,
            'title' => $announcement->translate('title'),
            'excerpt' => mb_substr(strip_tags((string) $announcement->translate('body')), 0, 200),
            'cover_url' => FileStorage::publicUrl($announcement->cover_path),
            'published_at' => $announcement->published_at?->toIso8601String(),
        ];
    }

    public function verifyCertificate(string $code, CertificateService $certificates): JsonResponse
    {
        $result = $certificates->verify($code);

        return $result
            ? response()->json(['data' => $result])
            : response()->json(['message' => __('Not Found'), 'data' => ['valid' => false]], 404);
    }

    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactMessage::create($data);

        return response()->json(['message' => app()->getLocale() === 'ar' ? 'تم استلام رسالتك، وسنتواصل معك قريباً.' : 'Thank you, we will get back to you shortly.'], 201);
    }

    private function statsPayload(): array
    {
        return [
            'programs' => Program::visible()->count(),
            'participants' => Registration::whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->distinct()->count('employee_id'),
            'certificates' => Certificate::where('status', 'valid')->count(),
            'schools' => School::where('status', 'active')->count(),
            'trainers' => Trainer::where('status', 'active')->count(),
            'training_hours' => (int) round(Certificate::where('status', 'valid')->sum('hours')),
            'employees' => Employee::count(),
            'satisfaction' => round((float) Evaluation::avg('satisfaction_score'), 1),
        ];
    }

    private function programQuery()
    {
        return Program::visible()
            ->with(['category', 'skills', 'trainers'])
            ->withCount(['registrations as seats_taken' => fn ($q) => $q->whereIn('status', Registration::SEAT_HOLDING)]);
    }

    private function newsQuery()
    {
        return Announcement::where('is_public', true)->whereNotNull('published_at')->where('published_at', '<=', now())->latest('published_at');
    }
}
