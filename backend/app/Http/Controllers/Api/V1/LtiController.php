<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\Program;
use App\Models\Registration;
use App\Services\Lti\LtiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/** The public side of TEDC as an LTI platform: JWKS, OIDC authentication, token, AGS, NRPS, deep-linking return and Basic Outcomes. */
class LtiController extends Controller
{
    public function __construct(private readonly LtiService $lti) {}

    public function jwks(): JsonResponse
    {
        return response()->json($this->lti->jwks(), 200, ['Cache-Control' => 'public, max-age=300']);
    }

    /** OIDC authentication request from the tool: answers with a form that posts the signed id_token back. */
    public function auth(Request $request): Response|JsonResponse
    {
        try {
            $r = $this->lti->authenticate($request->all());
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'invalid_request', 'error_description' => $e->getMessage()], 400);
        }
        $form = '<form id="f" method="POST" action="'.e($r['redirect_uri']).'"><input type="hidden" name="id_token" value="'.e($r['id_token']).'"><input type="hidden" name="state" value="'.e($r['state']).'"></form><script>document.getElementById("f").submit()</script>';

        return response('<!doctype html><meta charset="utf-8"><title>LTI</title>'.$form, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; script-src 'unsafe-inline'; form-action *"]);
    }

    public function token(Request $request): JsonResponse
    {
        try {
            return response()->json($this->lti->token($request->all()));
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'invalid_client', 'error_description' => $e->getMessage()], 400);
        }
    }

    public function lineItems(Request $request, CourseLesson $lesson): JsonResponse
    {
        $this->lti->bearer($request->header('Authorization'), LtiService::AGS_SCOPES[1]);
        $base = url("/api/v1/lti/ags/{$lesson->id}/lineitems/1");

        return response()->json([['id' => $base, 'scoreMaximum' => 100, 'label' => $lesson->title_en, 'resourceLinkId' => $lesson->id]], 200, ['Content-Type' => 'application/vnd.ims.lis.v2.lineitemcontainer+json']);
    }

    public function lineItem(Request $request, CourseLesson $lesson): JsonResponse
    {
        $this->lti->bearer($request->header('Authorization'), LtiService::AGS_SCOPES[1]);

        return response()->json(['id' => url("/api/v1/lti/ags/{$lesson->id}/lineitems/1"), 'scoreMaximum' => 100, 'label' => $lesson->title_en, 'resourceLinkId' => $lesson->id]);
    }

    public function score(Request $request, CourseLesson $lesson): Response
    {
        $auth = $this->lti->bearer($request->header('Authorization'), LtiService::AGS_SCOPES[2]);
        $d = $request->validate(['userId' => ['required', 'string'], 'scoreGiven' => ['nullable', 'numeric'], 'scoreMaximum' => ['required_with:scoreGiven', 'numeric', 'gt:0'], 'activityProgress' => ['required', 'in:Initialized,Started,InProgress,Submitted,Completed'], 'gradingProgress' => ['required', 'in:FullyGraded,Pending,PendingManual,Failed,NotReady'], 'timestamp' => ['nullable', 'string']]);
        $this->lti->acceptScore($lesson, $auth['tool'], $d);

        return response('', 204);
    }

    public function memberships(Request $request, Program $program): JsonResponse
    {
        $auth = $this->lti->bearer($request->header('Authorization'), LtiService::NRPS_SCOPE);
        $tool = $auth['tool'];
        $members = Registration::with('employee.user')->where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->limit(1000)->get()
            ->map(fn ($r) => array_filter(['user_id' => $r->employee->user_id, 'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'], 'status' => 'Active', 'name' => ($tool->privacy['share_name'] ?? true) ? $r->employee->user?->name : null, 'email' => ($tool->privacy['share_email'] ?? false) ? $r->employee->user?->email : null]))->values();

        return response()->json(['id' => url("/api/v1/lti/nrps/{$program->id}/memberships"), 'context' => ['id' => $program->id, 'label' => $program->code, 'title' => $program->title_en], 'members' => $members], 200, ['Content-Type' => 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json']);
    }

    /** The tool posts its selection (a signed JWT) back; the chosen items become lessons. */
    public function deepLinkReturn(Request $request): Response
    {
        try {
            $created = $this->lti->deepLinkReturn((string) $request->input('JWT'));
            $msg = count($created).' item(s) added.';
        } catch (RuntimeException $e) {
            return response('<!doctype html><meta charset="utf-8"><p>'.e($e->getMessage()).'</p>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return response('<!doctype html><meta charset="utf-8"><p>'.e($msg).'</p><script>try{window.opener&&window.opener.postMessage({type:"lti:deep-link-done"},"*")}catch(e){}</script>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** LTI 1.1 Basic Outcomes (replaceResult), OAuth 1.0a signed. */
    public function outcomes(Request $request): Response
    {
        $xml = (string) $request->getContent();
        try {
            $tool = $this->lti->verify11('POST', $request->url(), [], $request->header('Authorization'), $xml);
            $sx = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            $sx->registerXPathNamespace('o', 'http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0');
            $id = (string) ($sx->xpath('//o:sourcedId')[0] ?? '');
            $score = (float) ($sx->xpath('//o:textString')[0] ?? 0);
            $this->lti->outcome($tool, $id, max(0.0, min(1.0, $score)));
            $status = 'success';
        } catch (\Throwable) {
            $status = 'failure';
        }

        return response('<?xml version="1.0" encoding="UTF-8"?><imsx_POXEnvelopeResponse xmlns="http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0"><imsx_POXHeader><imsx_POXResponseHeaderInfo><imsx_version>V1.0</imsx_version><imsx_statusInfo><imsx_codeMajor>'.$status.'</imsx_codeMajor></imsx_statusInfo></imsx_POXResponseHeaderInfo></imsx_POXHeader><imsx_POXBody><replaceResultResponse/></imsx_POXBody></imsx_POXEnvelopeResponse>', $status === 'success' ? 200 : 401, ['Content-Type' => 'application/xml']);
    }
}
