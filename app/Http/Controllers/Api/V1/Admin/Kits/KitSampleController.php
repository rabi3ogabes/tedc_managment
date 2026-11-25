<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Http\Controllers\Controller;
use App\Models\TrainingKit;
use Database\Seeders\DemoSampleKitsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The fifteen ready sample kits (five per kind of program): what exists, and building them one at a time. */
class KitSampleController extends Controller
{
    public function index(): JsonResponse
    {
        $existing = TrainingKit::whereIn('code', array_column(DemoSampleKitsSeeder::samples(), 'code'))->pluck('id', 'code');

        return response()->json(['data' => collect(DemoSampleKitsSeeder::samples())->map(fn (array $s, int $i) => [
            'n' => $i + 1, 'code' => $s['code'], 'mode' => $s['mode'], 'title' => $s['ar'], 'built' => $existing->has($s['code']), 'kit_id' => $existing->get($s['code']),
        ])->values()]);
    }

    /** Builds sample number n (1–15). One per call keeps the request short, however slow the connection to the database is. */
    public function store(Request $request): JsonResponse
    {
        $n = (int) $request->validate(['n' => ['required', 'integer', 'between:1,'.count(DemoSampleKitsSeeder::samples())]])['n'];
        $kit = app(DemoSampleKitsSeeder::class)->build($n);

        return response()->json(['data' => ['n' => $n, 'kit_id' => $kit?->id, 'code' => $kit?->code, 'done' => $n >= count(DemoSampleKitsSeeder::samples())]], 201);
    }
}
