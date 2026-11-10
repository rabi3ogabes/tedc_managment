<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\LtiPlatform;
use App\Models\LtiToolLaunch;
use App\Models\Program;
use App\Services\Lti\LtiService;
use App\Services\Lti\LtiToolException;
use App\Services\Lti\LtiToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public side of TEDC as an LTI tool: OIDC login, the launch (1.3 and 1.1), TEDC's key set and the deep-linking
 * selection. Every request proves itself (a platform signature, a single-use state, an HMAC token). The small pages
 * answered here may be shown inside the registered platform's own pages; TEDC itself opens in its own window, because
 * its pages refuse to be framed.
 */
class LtiToolEndpointController extends Controller
{
    public function __construct(private readonly LtiToolService $tool, private readonly LtiService $lti) {}

    public function jwks(): JsonResponse
    {
        return response()->json($this->lti->jwks(), 200, ['Cache-Control' => 'public, max-age=300']);
    }

    public function login(Request $request): RedirectResponse|Response
    {
        try {
            return redirect()->away($this->tool->login($request->all()));
        } catch (LtiToolException $e) {
            return $this->failure($e);
        }
    }

    public function launch(Request $request): Response
    {
        try {
            $launch = $this->tool->launch((string) $request->input('id_token'), (string) $request->input('state'));
        } catch (LtiToolException $e) {
            return $this->failure($e);
        }

        return $this->respond($launch);
    }

    /** LTI 1.1: the fields exactly as the platform signed them (the raw form body, before any input clean-up). */
    public function launch11(Request $request): Response
    {
        $raw = (string) $request->getContent();
        if ($raw !== '') {
            parse_str($raw, $params);
        } else {
            $params = $request->request->all();
        }
        try {
            $launch = $this->tool->launch11($params, $request->url());
        } catch (LtiToolException $e) {
            return $this->failure($e);
        }

        return $this->respond($launch);
    }

    /** The instructor's choice from the selection page: a signed LtiDeepLinkingResponse posted back to the platform. */
    public function deepLink(Request $request): Response
    {
        $d = $request->validate(['launch' => ['required', 'string', 'max:64'], 'token' => ['required', 'string', 'max:128'], 'items' => ['nullable', 'array', 'max:50'], 'items.*' => ['string', 'max:80']]);
        try {
            $back = $this->tool->deepLinkResponse($d['launch'], $d['token'], $d['items'] ?? []);
        } catch (LtiToolException $e) {
            return $this->failure($e);
        }
        $launch = LtiToolLaunch::with('platform')->findOrFail($d['launch']);

        return $this->page('post', ['action' => $back['url'], 'jwt' => $back['jwt']], 200, $launch->platform, $this->lang($launch), [$this->origin($back['url'])]);
    }

    private function respond(LtiToolLaunch $launch): Response
    {
        $launch->loadMissing('platform', 'user');
        $lang = $this->lang($launch);
        if ($launch->message_type === 'LtiDeepLinkingRequest') {
            return $this->page('select', ['catalogue' => $this->tool->catalogue($launch), 'launch' => $launch->id, 'token' => $this->tool->selectionToken($launch), 'action' => url('/api/v1/lti/tool/deep-link')], 200, $launch->platform, $lang);
        }
        if ($launch->ticket) {
            return $this->page('link', ['url' => $this->tool->linkUrl($launch)], 200, $launch->platform, $lang);
        }
        $to = $this->tool->destination($launch);
        $title = $this->title($launch, $lang);
        if (! $this->tool->mayStartSession($launch->user)) {
            return $this->page('signin', ['title' => $title, 'url' => $this->tool->signInUrl($to)], 200, $launch->platform, $lang);
        }

        return $this->page('open', ['title' => $title, 'url' => $this->tool->handoff($launch->user, $to)], 200, $launch->platform, $lang);
    }

    private function title(LtiToolLaunch $launch, string $lang): string
    {
        $target = match ($launch->target_type) {
            'lesson' => CourseLesson::find($launch->target_id),
            'program' => Program::find($launch->target_id),
            default => null,
        };
        $name = $target ? (string) ($lang === 'en' ? ($target->title_en ?: $target->title_ar) : ($target->title_ar ?: $target->title_en)) : '';

        return $name !== '' ? $name : ($lang === 'en' ? 'Your training in TEDC' : 'تدريبك في TEDC');
    }

    private function lang(LtiToolLaunch $launch): string
    {
        return str_starts_with(strtolower((string) ($launch->claims['locale'] ?? '')), 'en') ? 'en' : 'ar';
    }

    private function failure(LtiToolException $e): Response
    {
        $lang = app()->getLocale() === 'en' ? 'en' : 'ar';

        return $this->page('error', ['message' => __('messages.lti_tool.'.$e->key, [], $lang)], 400, null, $lang);
    }

    /**
     * A small page of the launch. Only the registered platform's pages may show it in a frame; its forms post only to
     * TEDC and to the platform.
     *
     * @param  list<string>  $formTargets
     */
    private function page(string $mode, array $data, int $status, ?LtiPlatform $platform, string $lang, array $formTargets = []): Response
    {
        $origins = $platform?->origins() ?? [];
        $csp = "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; "
            .'form-action '.trim("'self' ".implode(' ', array_unique(array_merge($origins, array_filter($formTargets))))).'; '
            .'frame-ancestors '.trim("'self' ".implode(' ', $origins));

        return response()->view('lti.page', $data + ['mode' => $mode, 'lang' => $lang, 'platform' => $platform?->name, 'title' => $data['title'] ?? null, 'message' => $data['message'] ?? null], $status,
            ['Content-Security-Policy' => $csp, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return isset($p['scheme'], $p['host']) ? $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '') : '';
    }
}
