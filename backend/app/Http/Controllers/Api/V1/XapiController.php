<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cmi5Session;
use App\Services\Content\Cmi5Service;
use App\Services\Content\StandardsSettings;
use App\Services\Content\XapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use RuntimeException;

/** The xAPI 1.0.3 learning record store: statements, state and profile documents. Basic auth: LRS credentials or a cmi5 session. */
class XapiController extends Controller
{
    public function __construct(private readonly XapiService $xapi, private readonly StandardsSettings $settings, private readonly Cmi5Service $cmi5) {}

    private function headers(): array
    {
        return ['X-Experience-API-Version' => XapiService::VERSION];
    }

    private function fail(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['message' => $message], $status, $this->headers());
    }

    /** @return array{scope: string, session: ?Cmi5Session}|JsonResponse */
    private function auth(Request $request, bool $write): array|JsonResponse
    {
        $basic = base64_decode(substr((string) $request->header('Authorization'), 6)) ?: '';
        [$user, $pass] = array_pad(explode(':', $basic, 2), 2, '');
        if (str_starts_with((string) $request->header('Authorization'), 'Basic ') && $user !== '') {
            if ($session = $this->cmi5->authenticate($user, $pass)) {
                return ['scope' => 'session', 'session' => $session];
            }
            if ($c = $this->settings->credential($user, $pass)) {
                return ($write && $c['scope'] === 'read') ? $this->fail('This credential cannot write.', 403) : ['scope' => $c['scope'], 'session' => null];
            }
        }

        return response()->json(['message' => 'Unauthorized'], 401, $this->headers() + ['WWW-Authenticate' => 'Basic realm="xAPI"']);
    }

    private function versionError(Request $request): ?JsonResponse
    {
        return preg_match('/^1\.0(\.\d+)?$/', (string) $request->header('X-Experience-API-Version')) ? null : $this->fail('X-Experience-API-Version header is required (1.0.x).');
    }

    public function about(): JsonResponse
    {
        return response()->json(['version' => [XapiService::VERSION, '1.0.2', '1.0.1', '1.0.0']], 200, $this->headers());
    }

    public function statements(Request $request): JsonResponse|Response
    {
        $write = ! $request->isMethod('GET');
        $auth = $this->auth($request, $write);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        if ($e = $this->versionError($request)) {
            return $e;
        }

        if ($request->isMethod('GET')) {
            if ($request->filled('statementId') || $request->filled('voidedStatementId')) {
                $s = $this->xapi->find((string) ($request->query('statementId') ?: $request->query('voidedStatementId')), $request->filled('voidedStatementId'));

                return $s ? response()->json($s, 200, $this->headers()) : $this->fail('Statement not found.', 404);
            }

            return response()->json($this->xapi->query($request->query()), 200, $this->headers());
        }

        $body = $request->json()->all();
        $list = array_is_list($body) ? $body : [$body];
        $session = $auth['session'];
        try {
            if ($request->isMethod('PUT')) {
                abort_unless($request->filled('statementId'), 400, 'statementId is required.');
                $list[0]['id'] = $list[0]['id'] ?? $request->query('statementId');
                abort_unless($list[0]['id'] === $request->query('statementId'), 400, 'statementId does not match.');
            }
            $ids = [];
            foreach ($list as $s) {
                $r = $this->xapi->store($s, $session?->registration_id, $session?->lesson_id);
                $ids[] = $r['id'];
                $session && $this->cmi5->onStatement($session, $s);
            }
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return $request->isMethod('PUT') ? response('', 204, $this->headers()) : response()->json($ids, 200, $this->headers());
    }

    /** State, activity profile and agent profile documents. */
    public function documents(Request $request, string $kind): JsonResponse|Response
    {
        $auth = $this->auth($request, ! $request->isMethod('GET'));
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        if ($e = $this->versionError($request)) {
            return $e;
        }
        $kinds = ['state' => 'state', 'profile' => 'activity_profile', 'agent-profile' => 'agent_profile'];
        $type = $kinds[$kind] ?? abort(404);
        $idKey = $type === 'state' ? 'stateId' : 'profileId';
        $agent = $request->query('agent') ? (json_decode((string) $request->query('agent'), true) ?: []) : [];
        $scope = ['activity_id' => $request->query('activityId'), 'agent_key' => $agent ? $this->xapi->actorKey($agent) : null, 'registration' => $request->query('registration')];
        $docId = $request->query($idKey);

        switch ($request->method()) {
            case 'GET':
                if (! $docId) {
                    return response()->json($this->xapi->documentIds($type, $scope), 200, $this->headers());
                }
                $doc = $this->xapi->document($type, $scope, $docId);

                return $doc ? response($doc->content, 200, $this->headers() + ['Content-Type' => $doc->content_type]) : response('', 404, $this->headers());
            case 'PUT':
            case 'POST':
                abort_unless($docId, 400, "{$idKey} is required.");
                $this->xapi->saveDocument($type, $scope, $docId, (string) $request->getContent(), (string) ($request->header('Content-Type') ?: 'application/json'), $request->isMethod('POST'));

                return response('', 204, $this->headers());
            case 'DELETE':
                $this->xapi->deleteDocument($type, $scope, $docId);

                return response('', 204, $this->headers());
        }

        abort(405);
    }

    /** cmi5: the unit fetches its auth token once. */
    public function cmi5Fetch(Cmi5Session $cmi5Session): JsonResponse
    {
        $token = $this->cmi5->fetchToken($cmi5Session);

        return $token ? response()->json(['auth-token' => $token]) : response()->json(['error-code' => '1', 'error-text' => 'The token was already fetched or the session expired.'], 400);
    }
}
