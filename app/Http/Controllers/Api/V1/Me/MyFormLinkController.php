<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\Saber\FormLinks;
use Illuminate\Http\JsonResponse;

/**
 * Where a programme's form link (`/f/t/{code}`, `/f/r/{code}`, usually scanned from a QR code in the room) takes the signed-in person:
 * their own trainee evaluation or trainer self-reflection, or a clear word on why not yet (docs/saber/07-programme-forms.md).
 */
class MyFormLinkController extends Controller
{
    public function __construct(private readonly FormLinks $links) {}

    public function trainee(string $code): JsonResponse
    {
        return response()->json(['data' => $this->links->traineeFor($this->program($code), $this->user())]);
    }

    public function reflection(string $code): JsonResponse
    {
        return response()->json(['data' => $this->links->reflectionFor($this->program($code), $this->user())]);
    }

    private function program(string $code): Program
    {
        return Program::where('code', $code)->whereNotIn('status', [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED])->firstOrFail();
    }
}
