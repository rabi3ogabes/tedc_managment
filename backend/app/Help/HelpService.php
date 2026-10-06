<?php

namespace App\Help;

use App\Models\HelpArticle;
use App\Models\HelpFeedback;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTour;
use App\Services\Cms\HtmlSanitizer;
use App\Services\FileStorage;
use App\Services\ThemeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;

/** The in-product help centre: articles by role and page, versions, feedback, guided tours and the role manuals as PDF. */
class HelpService
{
    /** Roles that get their own manual (the role slug is also the manual key). */
    public const MANUAL_ROLES = [
        Role::SUPER_ADMIN, Role::CENTER_ADMIN, Role::COORDINATOR, Role::TRAINER, Role::EMPLOYEE, Role::SUPERVISOR, Role::ACADEMIC_DEPUTY, Role::SCHOOL_ADMIN,
        Role::TRAINING_HEAD, Role::CENTER_LEADERSHIP, Role::KIT_DEVELOPER, Role::QA_REVIEWER, Role::PLANNING_HEAD, Role::PLANNING_SPECIALIST, Role::LOGISTICS_OFFICER,
        Role::EXECUTIVE, Role::FINANCE_OFFICER,
    ];

    /** The release shown by the «What's new» tour; changing it shows the tour again to everyone once. */
    public const RELEASE = '2026.1';

    /** Support channels and service expectations shown on the help page (editable later through settings). */
    public function support(): array
    {
        $s = (array) config('tedc.support', []);

        return [
            'channels' => [
                ['key' => 'phone', 'label_ar' => 'الهاتف', 'label_en' => 'Phone', 'value' => $s['phone'] ?? '', 'hours_ar' => $s['hours_ar'] ?? 'الأحد – الخميس، 7:00 ص – 3:00 م', 'hours_en' => $s['hours_en'] ?? 'Sunday – Thursday, 7:00 – 15:00'],
                ['key' => 'email', 'label_ar' => 'البريد الإلكتروني', 'label_en' => 'Email', 'value' => $s['email'] ?? '', 'hours_ar' => 'على مدار الساعة، يُرد في ساعات العمل', 'hours_en' => 'Always open; answered in working hours'],
                ['key' => 'saaed', 'label_ar' => 'بوابة سعيد', 'label_en' => 'Saaed portal', 'value' => $s['saaed_url'] ?? '', 'hours_ar' => 'تذاكر مرتبطة بحالتها داخل المنصة', 'hours_en' => 'Tickets whose status shows inside the portal'],
            ],
            'sla' => [
                ['priority' => 'P1', 'response_ar' => '15 دقيقة', 'response_en' => '15 minutes', 'resolution_ar' => 'ساعتان', 'resolution_en' => '2 hours'],
                ['priority' => 'P2', 'response_ar' => '30 دقيقة', 'response_en' => '30 minutes', 'resolution_ar' => '4 ساعات', 'resolution_en' => '4 hours'],
                ['priority' => 'P3', 'response_ar' => 'ساعتان', 'response_en' => '2 hours', 'resolution_ar' => 'يوم عمل', 'resolution_en' => '1 business day'],
                ['priority' => 'P4', 'response_ar' => '4 ساعات', 'response_en' => '4 hours', 'resolution_ar' => 'يوما عمل', 'resolution_en' => '2 business days'],
            ],
        ];
    }

    // ---- reading --------------------------------------------------------------------------------------

    /** Role slugs of every grant the user holds (an article is shown if it targets any of them). */
    public function roleSlugs(User $user): array
    {
        return $user->effectiveRoles()->pluck('slug')->unique()->values()->all();
    }

    public function visibleTo(User $user, bool $includeDrafts = false): Collection
    {
        $mine = $this->roleSlugs($user);
        $all = HelpArticle::query()->when(! $includeDrafts, fn ($q) => $q->where('status', 'published'))->orderBy('module')->orderBy('sort_order')->get();

        return $all->filter(fn (HelpArticle $a) => $this->targets($a, $mine))->values();
    }

    /** An article with no roles is for everyone; the administrator roles see every article. */
    private function targets(HelpArticle $a, array $mine): bool
    {
        $roles = (array) $a->roles;

        return $roles === [] || array_intersect($roles, $mine) !== [] || array_intersect([Role::SUPER_ADMIN, Role::CENTER_ADMIN], $mine) !== [];
    }

    /** Articles offered on a page: those whose related routes match (a * matches any run of characters). */
    public function forRoute(User $user, string $path): Collection
    {
        $path = '/'.trim(Str::before($path, '?'), '/');

        return $this->visibleTo($user)->filter(function (HelpArticle $a) use ($path) {
            foreach ((array) $a->related_routes as $pattern) {
                $pattern = '/'.trim((string) $pattern, '/');
                if ($pattern === $path || Str::is($pattern, $path)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    public function search(User $user, string $q): Collection
    {
        $q = Str::lower(trim($q));
        if ($q === '') {
            return $this->visibleTo($user);
        }
        $words = array_filter(preg_split('/\s+/u', $q) ?: []);

        return $this->visibleTo($user)->map(function (HelpArticle $a) use ($words) {
            $title = Str::lower($a->title_ar.' '.$a->title_en);
            $body = Str::lower(strip_tags($a->body_ar.' '.$a->body_en));
            $score = 0;
            foreach ($words as $w) {
                $score += (str_contains($title, $w) ? 5 : 0) + (str_contains($body, $w) ? 1 : 0);
            }

            return [$score, $a];
        })->filter(fn ($p) => $p[0] > 0)->sortByDesc(fn ($p) => $p[0])->map(fn ($p) => $p[1])->values();
    }

    public function present(HelpArticle $a, bool $full = true): array
    {
        $out = [
            'id' => $a->id, 'slug' => $a->slug, 'title_ar' => $a->title_ar, 'title_en' => $a->title_en, 'module' => $a->module, 'roles' => $a->roles ?? [],
            'related_routes' => $a->related_routes ?? [], 'version' => $a->version, 'status' => $a->status, 'sort_order' => $a->sort_order,
            'has_video' => (bool) ($a->video_url || $a->video_asset), 'updated_at' => $a->updated_at?->toIso8601String(),
        ];
        if (! $full) {
            return $out + ['excerpt_ar' => Str::limit(trim(strip_tags((string) $a->body_ar)), 140), 'excerpt_en' => Str::limit(trim(strip_tags((string) $a->body_en)), 140)];
        }

        return $out + [
            'body_ar' => $a->body_ar, 'body_en' => $a->body_en, 'video_url' => $a->video_url, 'video_asset_url' => FileStorage::publicUrl($a->video_asset), 'video_asset' => $a->video_asset,
            'screenshots' => collect($a->screenshots ?? [])->map(fn ($s) => $s + ['url' => FileStorage::publicUrl($s['path'] ?? null)])->all(),
        ];
    }

    // ---- writing --------------------------------------------------------------------------------------

    /** Creates or updates an article; every change of the text keeps the earlier text as a numbered version. */
    public function save(array $d, ?HelpArticle $article, ?User $actor): HelpArticle
    {
        foreach (['body_ar', 'body_en'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = HtmlSanitizer::clean($d[$k]);
            }
        }
        $d['roles'] = array_values(array_unique((array) ($d['roles'] ?? $article?->roles ?? [])));
        $d['related_routes'] = array_values(array_filter(array_map(fn ($r) => '/'.trim((string) $r, '/'), (array) ($d['related_routes'] ?? $article?->related_routes ?? []))));
        $d['updated_by'] = $actor?->id;
        if (($d['module'] ?? 'x') === null) {
            unset($d['module']);
        }

        return DB::transaction(function () use ($d, $article, $actor) {
            if (! $article) {
                $article = HelpArticle::create($d + ['version' => 1]);
                $this->snapshot($article, $actor);

                return $article;
            }
            $textChanged = collect(['title_ar', 'title_en', 'body_ar', 'body_en', 'video_url', 'video_asset'])->contains(fn ($k) => array_key_exists($k, $d) && $d[$k] !== $article->{$k});
            $article->fill($d + ($textChanged ? ['version' => $article->version + 1] : []))->save();
            if ($textChanged) {
                $this->snapshot($article, $actor);
            }

            return $article;
        });
    }

    private function snapshot(HelpArticle $a, ?User $actor): void
    {
        $a->refresh();
        $a->versions()->create(['version' => $a->version, 'created_by' => $actor?->id, 'snapshot' => $a->only(['title_ar', 'title_en', 'body_ar', 'body_en', 'video_url', 'video_asset', 'roles', 'related_routes', 'module', 'screenshots'])]);
    }

    public function rollback(HelpArticle $a, int $version, ?User $actor): HelpArticle
    {
        $v = $a->versions()->where('version', $version)->firstOrFail();

        return $this->save($v->snapshot + ['status' => $a->status], $a, $actor);
    }

    public function feedback(HelpArticle $a, ?User $user, bool $helpful, ?string $comment): HelpFeedback
    {
        return HelpFeedback::updateOrCreate(
            ['article_id' => $a->id, 'user_id' => $user?->id, 'article_version' => $a->version],
            ['helpful' => $helpful, 'comment' => $comment ? Str::limit(strip_tags($comment), 1000, '') : null],
        );
    }

    /** Per article: helpful and not-helpful counts and the latest comments, worst first, so authors know what to rewrite. */
    public function analytics(): array
    {
        $rows = HelpFeedback::query()->select('article_id', DB::raw('sum(case when helpful then 1 else 0 end) as yes'), DB::raw('sum(case when helpful then 0 else 1 end) as no'))->groupBy('article_id')->get()->keyBy('article_id');
        $articles = HelpArticle::query()->get()->keyBy('id');

        return $rows->map(function ($r) use ($articles) {
            $a = $articles[$r->article_id] ?? null;
            if (! $a) {
                return null;
            }
            $yes = (int) $r->yes;
            $no = (int) $r->no;

            return [
                'article_id' => $a->id, 'slug' => $a->slug, 'title_ar' => $a->title_ar, 'title_en' => $a->title_en, 'helpful' => $yes, 'not_helpful' => $no,
                'score' => ($yes + $no) ? round($yes / ($yes + $no) * 100) : null,
                'comments' => HelpFeedback::where('article_id', $a->id)->whereNotNull('comment')->latest()->limit(5)->pluck('comment')->all(),
            ];
        })->filter()->sortBy([['not_helpful', 'desc'], ['helpful', 'asc']])->values()->all();
    }

    // ---- tours ----------------------------------------------------------------------------------------

    /** The tour a user has not finished yet: first-login for their main role, then «what's new» for the release. */
    public function pendingTour(User $user): ?array
    {
        $done = UserTour::where('user_id', $user->id)->pluck('tour_key')->all();
        $role = $this->primaryRole($user);
        $first = "first-login:{$role}";
        if (! in_array($first, $done, true)) {
            return ['key' => $first, 'kind' => 'first-login', 'steps' => TourCatalog::firstLogin($role)];
        }
        $new = 'whats-new:'.self::RELEASE;
        if (! in_array($new, $done, true)) {
            return ['key' => $new, 'kind' => 'whats-new', 'steps' => TourCatalog::whatsNew()];
        }

        return null;
    }

    public function primaryRole(User $user): string
    {
        $mine = $this->roleSlugs($user);
        foreach (self::MANUAL_ROLES as $r) {
            if (in_array($r, $mine, true)) {
                return $r;
            }
        }

        return $mine[0] ?? Role::EMPLOYEE;
    }

    public function finishTour(User $user, string $key, string $state): UserTour
    {
        return UserTour::updateOrCreate(['user_id' => $user->id, 'tour_key' => $key], ['state' => $state === 'dismissed' ? 'dismissed' : 'done']);
    }

    // ---- manuals --------------------------------------------------------------------------------------

    /** The articles of one role's manual, in reading order. */
    public function manualArticles(string $role): Collection
    {
        return HelpArticle::query()->where('status', 'published')->orderBy('module')->orderBy('sort_order')->get()
            ->filter(fn (HelpArticle $a) => ($a->roles ?? []) === [] || in_array($role, $a->roles ?? [], true))->values();
    }

    /** A branded PDF manual with a title page, version table and contents, always built from the current article versions. */
    public function manualPdf(string $role, string $lang): string
    {
        $lang = $lang === 'en' ? 'en' : 'ar';
        $roleModel = Role::where('slug', $role)->first();
        $roleName = $roleModel ? ($lang === 'ar' ? $roleModel->name_ar : $roleModel->name_en) : $role;
        $theme = app(ThemeService::class);
        $articles = $this->manualArticles($role)->map(function (HelpArticle $a) use ($lang) {
            $a->setAttribute('shots', collect($a->screenshots ?? [])->map(fn ($s) => ['caption' => $s['caption_'.$lang] ?? '', 'data' => $this->embed($s['path'] ?? null)])->all());

            return $a;
        });
        $html = view('help.manual', [
            'lang' => $lang, 'dir' => $lang === 'ar' ? 'rtl' : 'ltr', 'roleName' => $roleName, 'articles' => $articles, 'center' => $theme->centerName(),
            'logo' => $theme->get()['identity']['logo_ar'] ?? null, 'date' => now()->toDateString(), 'release' => self::RELEASE,
        ])->render();
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 16, 'margin_right' => 16, 'margin_top' => 18, 'margin_bottom' => 18, 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetTitle(($lang === 'ar' ? 'دليل المستخدم — ' : 'User manual — ').$roleName);
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function embed(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        try {
            $bytes = app(FileStorage::class)->usesSupabase() || app(FileStorage::class)->usesAzure() ? app(FileStorage::class)->get('public', $path) : (string) file_get_contents(Storage::disk('public')->path($path));
            $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

            return $bytes ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
