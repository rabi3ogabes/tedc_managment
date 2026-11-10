<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\DevicePunch;
use App\Services\FingerprintGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Fingerprint / badge devices: registry, connection test, CSV import and the public signed webhook. */
class AttendanceDeviceController extends Controller
{
    public function __construct(private readonly FingerprintGateway $gateway) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => AttendanceDevice::latest()->get()->map(fn ($d) => $this->present($d))->all()]);
    }

    public function save(Request $request, ?AttendanceDevice $device = null): JsonResponse
    {
        $r = $device ? 'sometimes' : 'required';
        $d = $request->validate(['name' => [$r, 'string', 'max:120'], 'vendor' => [$r, Rule::in(['zkteco', 'suprema', 'generic_http', 'csv'])], 'serial' => ['nullable', 'string', 'max:80'], 'location_room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'], 'secret' => ['nullable', 'string', 'min:6', 'max:120'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])]]);
        $secret = $d['secret'] ?? null;
        unset($d['secret']);
        $device = $device ? tap($device)->update($d) : AttendanceDevice::create($d);
        if ($secret) {
            $device->update(['api_config' => array_replace($device->api_config ?? [], ['secret' => $secret])]);
        }

        return response()->json(['data' => $this->present($device->fresh())], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(AttendanceDevice $device): JsonResponse
    {
        $device->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Without a network path to the device, "test" confirms what we can: it is registered, has a room and a secret, and when it last sent punches. */
    public function test(AttendanceDevice $device): JsonResponse
    {
        $issues = array_values(array_filter([
            $device->location_room_id ? null : 'no_room', ($device->api_config['secret'] ?? null) || $device->vendor === 'csv' ? null : 'no_secret',
        ]));

        return response()->json(['data' => ['ok' => $issues === [], 'issues' => $issues, 'last_sync_at' => $device->last_sync_at?->toIso8601String(), 'punches' => DevicePunch::where('device_id', $device->id)->count()]]);
    }

    public function import(Request $request, AttendanceDevice $device): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);

        return response()->json(['data' => $this->gateway->ingest($device, $this->gateway->parseCsv((string) file_get_contents($request->file('file')->getRealPath())))]);
    }

    public function log(AttendanceDevice $device): JsonResponse
    {
        return response()->json(['data' => DevicePunch::where('device_id', $device->id)->latest('punched_at')->limit(100)->get(['person_ref', 'punched_at', 'direction', 'outcome'])->all()]);
    }

    /** Signed push from a device or its bridge (no user session): the HMAC of the raw body with the device secret. */
    public function webhook(Request $request, AttendanceDevice $device): JsonResponse
    {
        $body = $request->getContent();
        if ($device->status !== 'active' || ! $this->gateway->verifySignature($device, $body, $request->header('X-Signature'))) {
            return response()->json(['message' => 'invalid signature'], 401);
        }

        return response()->json(['data' => $this->gateway->ingest($device, $this->gateway->parse($body, $request->header('Content-Type')))]);
    }

    private function present(AttendanceDevice $d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'vendor' => $d->vendor, 'serial' => $d->serial, 'location_room_id' => $d->location_room_id, 'status' => $d->status, 'last_sync_at' => $d->last_sync_at?->toIso8601String(), 'last_error' => $d->last_error, 'has_secret' => (bool) ($d->api_config['secret'] ?? false)];
    }
}
