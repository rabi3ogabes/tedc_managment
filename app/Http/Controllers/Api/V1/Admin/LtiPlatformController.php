<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LtiPlatform;
use App\Models\LtiToolLaunch;
use App\Models\Program;
use App\Models\Role;
use App\Services\Lti\Jwt;
use App\Services\Lti\LtiService;
use App\Services\Lti\LtiToolException;
use App\Services\Lti\LtiToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Settings → Integrations → LTI platforms: the LMSs allowed to launch TEDC as a tool, what they may reach, and TEDC's tool configuration to give them. */
class LtiPlatformController extends Controller
{
    public function __construct(private readonly LtiToolService $tool) {}

    public function index(): JsonResponse
    {
        $rows = LtiPlatform::orderBy('name')->get();
        $launches = LtiToolLaunch::selectRaw('platform_id, count(*) as n, max(created_at) as last_at')->groupBy('platform_id')->get()->keyBy('platform_id');

        return response()->json(['data' => $rows->map(fn (LtiPlatform $p) => $this->present($p) + ['launches' => (int) ($launches[$p->id]->n ?? 0), 'last_launch_at' => $launches[$p->id]->last_at ?? null])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $p = $this->save(new LtiPlatform, $request);

        return response()->json(['data' => $this->present($p)], 201);
    }

    public function update(Request $request, LtiPlatform $ltiPlatform): JsonResponse
    {
        return response()->json(['data' => $this->present($this->save($ltiPlatform, $request))]);
    }

    public function destroy(LtiPlatform $ltiPlatform): JsonResponse
    {
        AuditLog::create(['user_id' => $this->user()->id, 'action' => 'lti_platform_deleted', 'auditable_type' => LtiPlatform::class, 'auditable_id' => $ltiPlatform->id, 'old_values' => ['name' => $ltiPlatform->name, 'issuer' => $ltiPlatform->issuer]]);
        $ltiPlatform->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** What to enter in the other LMS: TEDC's login, launch, deep-linking and key-set addresses. */
    public function toolConfig(LtiService $lti): JsonResponse
    {
        $k = $lti->keys();

        return response()->json(['data' => $this->tool->urls() + ['public_jwk' => Jwt::jwk($k['public'], $k['kid']), 'public_key' => $k['public'],
            'custom_parameters' => ['tedc_target' => 'lesson:<lesson id> | program:<programme code>'], 'scopes' => [LtiToolService::SCORE_SCOPE, LtiService::NRPS_SCOPE]]]);
    }

    /** Checks the configuration from TEDC's side: complete, reachable keys, at least one programme to reach. */
    public function test(LtiPlatform $ltiPlatform): JsonResponse
    {
        $p = $ltiPlatform;
        $checks = [];
        if ($p->version === '1.3') {
            $missing = array_keys(array_filter(['issuer' => $p->issuer, 'client_id' => $p->client_id, 'auth_login_url' => $p->auth_login_url, 'auth_token_url' => $p->auth_token_url], fn ($v) => blank($v)));
            $checks[] = ['key' => 'config', 'ok' => $missing === [] && (array) $p->deployment_ids !== [], 'detail' => $missing ? 'Missing: '.implode(', ', $missing) : ((array) $p->deployment_ids === [] ? 'No deployment id' : null)];
            try {
                $pem = $p->public_key ?: $this->tool->platformKey($p->fresh(), null);
                $checks[] = ['key' => 'jwks', 'ok' => openssl_pkey_get_public($pem) !== false, 'detail' => $p->public_key ? 'Public key pasted' : 'Key set fetched'];
            } catch (Throwable $e) {
                $checks[] = ['key' => 'jwks', 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
            }
            try {
                $status = Http::timeout(8)->asForm()->post((string) $p->auth_token_url, ['grant_type' => 'client_credentials'])->status();
                $checks[] = ['key' => 'token', 'ok' => $status > 0 && $status < 500, 'detail' => "HTTP {$status}"];
            } catch (Throwable $e) {
                $checks[] = ['key' => 'token', 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
            }
        } else {
            $checks[] = ['key' => 'config', 'ok' => filled($p->consumer_key) && filled($p->consumer_secret), 'detail' => null];
        }
        $checks[] = ['key' => 'programs', 'ok' => $p->programIds() !== [], 'detail' => count($p->programIds()).' programme(s)'];

        return response()->json(['data' => ['ok' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks]]);
    }

    /** The platform's members of a course (NRPS), from the latest launch of that course that offered the service. */
    public function roster(Request $request, LtiPlatform $ltiPlatform): JsonResponse
    {
        $context = $request->validate(['context_id' => ['nullable', 'string', 'max:255']])['context_id'] ?? null;
        $launch = LtiToolLaunch::where('platform_id', $ltiPlatform->id)->whereNotNull('nrps_url')->when($context, fn ($q) => $q->where('context_id', $context))->latest('created_at')->first();
        if (! $launch) {
            return response()->json(['message' => __('messages.lti_tool.no_roster'), 'code' => 'lti_no_roster'], 422);
        }
        try {
            return response()->json(['data' => ['context_id' => $launch->context_id, 'members' => $this->tool->roster($launch)]]);
        } catch (LtiToolException $e) {
            return response()->json(['message' => __('messages.lti_tool.'.$e->key), 'code' => 'lti_'.$e->key], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('messages.lti_tool.roster_failed'), 'code' => 'lti_roster_failed'], 502);
        }
    }

    private function save(LtiPlatform $p, Request $request): LtiPlatform
    {
        $creating = ! $p->exists;
        $version = (string) $request->input('version', $p->version ?? '1.3');
        $is13 = $version === '1.3';
        $req = fn (bool $needed) => $creating && $needed ? 'required' : 'sometimes';
        $d = $request->validate([
            'name' => [$req(true), 'string', 'max:120'], 'version' => [$req(true), Rule::in(['1.3', '1.1'])], 'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'issuer' => [$req($is13), 'string', 'max:255'], 'client_id' => [$req($is13), 'string', 'max:255'], 'deployment_ids' => [$req($is13), 'array', 'max:20'], 'deployment_ids.*' => ['string', 'max:255'],
            'auth_login_url' => [$req($is13), 'nullable', 'url', 'max:500'], 'auth_token_url' => [$req($is13), 'nullable', 'url', 'max:500'], 'jwks_url' => ['nullable', 'url', 'max:500'], 'public_key' => ['nullable', 'string', 'max:5000'],
            'consumer_key' => [$req(! $is13), 'nullable', 'string', 'max:120', Rule::unique('lti_platforms', 'consumer_key')->ignore($p->id)], 'consumer_secret' => [$req(! $is13), 'nullable', 'string', 'min:16', 'max:255'],
            'settings' => ['sometimes', 'array'], 'settings.program_ids' => ['sometimes', 'array', 'max:500'], 'settings.program_ids.*' => ['uuid', Rule::exists('programs', 'id')],
            'settings.trusted_domains' => ['sometimes', 'array', 'max:20'], 'settings.trusted_domains.*' => ['string', 'max:120', 'regex:/^@?[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'settings.roles' => ['sometimes', 'array'], 'settings.roles.learner' => ['sometimes', Rule::in(LtiToolService::LAUNCH_ROLES)], 'settings.roles.instructor' => ['sometimes', Rule::in(LtiToolService::LAUNCH_ROLES)],
        ]);
        if (filled($d['public_key'] ?? null) && openssl_pkey_get_public((string) $d['public_key']) === false) {
            throw ValidationException::withMessages(['public_key' => __('messages.lti_tool.bad_key')]);
        }
        if (! $is13) {
            // LTI 1.1 has no issuer: the consumer key names the platform.
            $key = (string) ($d['consumer_key'] ?? $p->consumer_key);
            $d['issuer'] = 'lti11:'.$key;
            $d['client_id'] = $key;
        } elseif ($creating && blank($d['jwks_url'] ?? null) && blank($d['public_key'] ?? null)) {
            throw ValidationException::withMessages(['jwks_url' => __('messages.lti_tool.key_required')]);
        }
        $issuer = (string) ($d['issuer'] ?? $p->issuer);
        $client = (string) ($d['client_id'] ?? $p->client_id);
        if (LtiPlatform::where('issuer', $issuer)->where('client_id', $client)->when($p->exists, fn ($q) => $q->whereKeyNot($p->id))->exists()) {
            throw ValidationException::withMessages(['client_id' => __('messages.lti_tool.duplicate')]);
        }
        if (array_key_exists('settings', $d)) {
            $d['settings'] = array_replace(['program_ids' => [], 'trusted_domains' => [], 'roles' => ['learner' => Role::EMPLOYEE, 'instructor' => Role::EMPLOYEE]], (array) ($p->settings ?? []), $d['settings']);
            $d['settings']['trusted_domains'] = array_values(array_unique(array_map(fn ($x) => strtolower(ltrim((string) $x, '@')), (array) $d['settings']['trusted_domains'])));
        }
        if ($creating) {
            $d += ['status' => 'active', 'settings' => ['program_ids' => [], 'trusted_domains' => [], 'roles' => ['learner' => Role::EMPLOYEE, 'instructor' => Role::EMPLOYEE]]];
        }
        if (array_key_exists('consumer_secret', $d) && blank($d['consumer_secret'])) {
            unset($d['consumer_secret']);   // blank keeps the stored secret
        }
        $p->fill($d)->save();
        AuditLog::create(['user_id' => $this->user()->id, 'action' => $creating ? 'lti_platform_created' : 'lti_platform_updated', 'auditable_type' => LtiPlatform::class, 'auditable_id' => $p->id,
            'new_values' => ['name' => $p->name, 'issuer' => $p->issuer, 'client_id' => $p->client_id, 'status' => $p->status, 'settings' => $p->settings]]);

        return $p->fresh();
    }

    /** @return array<string, mixed> */
    private function present(LtiPlatform $p): array
    {
        $programs = Program::whereIn('id', $p->programIds())->get(['id', 'code', 'title_ar', 'title_en']);

        return $p->only(['id', 'name', 'version', 'issuer', 'client_id', 'deployment_ids', 'auth_login_url', 'auth_token_url', 'jwks_url', 'consumer_key', 'status', 'settings'])
            + ['has_public_key' => filled($p->public_key), 'has_secret' => filled($p->consumer_secret), 'programs' => $programs, 'created_at' => $p->created_at?->toIso8601String()];
    }
}
