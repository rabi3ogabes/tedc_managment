<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Ministry\HrSync;
use App\Integrations\Ministry\LicenceSync;
use App\Integrations\Ministry\NsisSync;
use App\Integrations\Ministry\QnedsPublisher;
use App\Integrations\Ministry\SijilArchive;
use App\Models\IntegrationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Integration Hub: a card per system with status, settings, test, sync and call logs. */
class IntegrationsController extends Controller
{
    public function __construct(private readonly IntegrationManager $hub) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(array_keys(IntegrationRegistry::all()))->map(fn ($k) => $this->hub->present($k))->values()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]), 404);
        // Identity settings can also be managed by whoever may manage single sign-on.
        abort_unless($this->user()->hasPermission('integrations.manage') || (in_array($key, ['entra', 'ldap'], true) && $this->user()->hasPermission('sso.manage')), 403);
        $d = $request->validate(['driver' => ['sometimes', Rule::in(IntegrationRegistry::all()[$key]['drivers'])], 'enabled' => ['sometimes', 'boolean'], 'settings' => ['sometimes', 'array'], 'clear' => ['sometimes', 'array']]);

        return response()->json(['data' => $this->hub->update($key, $d)]);
    }

    public function check(string $key): JsonResponse
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]), 404);
        $this->hub->check($key);

        return response()->json(['data' => $this->hub->present($key)]);
    }

    /** Runs the system's sync now (HR, licences…). */
    public function sync(string $key): JsonResponse
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]), 404);
        $result = match ($key) {
            'hr', 'mawared' => app(HrSync::class)->run($key),
            'licences' => app(LicenceSync::class)->run(),
            'nsis' => app(NsisSync::class)->run(),
            'qneds' => app(QnedsPublisher::class)->publish(),
            'sijil' => app(SijilArchive::class)->run(),
            default => abort(422, 'This system has nothing to sync.'),
        };

        return response()->json(['data' => $result, 'integration' => $this->hub->present($key)]);
    }

    public function logs(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]), 404);
        $f = $request->validate(['status' => ['nullable', Rule::in(['ok', 'error'])], 'direction' => ['nullable', Rule::in(['in', 'out'])]]);

        return response()->json(IntegrationLog::where('integration_key', $key)->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))->latest('created_at')->paginate($this->perPage($request)));
    }
}
