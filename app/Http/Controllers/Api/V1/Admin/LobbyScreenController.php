<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\FileStorage;
use App\Services\LobbyScreenService;
use App\Services\LobbyScreenSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Lobby screen: the slideshow of today's programs and the images shown after them. */
class LobbyScreenController extends Controller
{
    public function __construct(private readonly LobbyScreenSettings $settings, private readonly LobbyScreenService $screen, private readonly FileStorage $files) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'show_programs' => ['sometimes', 'boolean'], 'show_clock' => ['sometimes', 'boolean'], 'show_progress' => ['sometimes', 'boolean'],
            'programs_seconds' => ['sometimes', 'integer', 'between:5,300'], 'programs_per_slide' => ['sometimes', 'integer', 'between:1,8'],
            'slide_seconds' => ['sometimes', 'integer', 'between:3,600'], 'transition' => ['sometimes', Rule::in(LobbyScreenSettings::TRANSITIONS)],
            'transition_ms' => ['sometimes', 'integer', 'between:0,3000'], 'language' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);
        $this->settings->update($data, $request->user());

        return $this->show();
    }

    /** Adds an image as the next slide (portrait 1080 × 1920 looks best; any picture is fitted). */
    public function addSlide(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'title' => ['nullable', 'string', 'max:120']]);
        abort_if(count($this->settings->all()['slides']) >= 40, 422, __('messages.lobby.too_many'));
        $path = $this->files->uploadPublic($request->file('image'), 'lobby');
        $this->settings->addSlide($path, (string) $request->input('title', pathinfo($request->file('image')->getClientOriginalName(), PATHINFO_FILENAME)));

        return response()->json(['data' => $this->present()], 201);
    }

    public function updateSlide(Request $request, string $slide): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'], 'seconds' => ['sometimes', 'nullable', 'integer', 'between:3,600'], 'transition' => ['sometimes', 'nullable', Rule::in(LobbyScreenSettings::TRANSITIONS)],
            'enabled' => ['sometimes', 'boolean'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_unless($this->settings->updateSlide($slide, $data), 404);

        return $this->show();
    }

    public function destroySlide(string $slide): JsonResponse
    {
        $gone = $this->settings->removeSlide($slide);
        abort_unless($gone, 404);
        rescue(fn () => $this->files->delete('public', $gone['path']), null, false);

        return $this->show();
    }

    public function order(Request $request): JsonResponse
    {
        $this->settings->reorder($request->validate(['ids' => ['required', 'array', 'max:60'], 'ids.*' => ['string', 'max:20']])['ids']);

        return $this->show();
    }

    public function regenerateToken(): JsonResponse
    {
        $this->settings->token(regenerate: true);

        return $this->show();
    }

    private function present(): array
    {
        $s = $this->settings->all();

        return [
            'settings' => collect($s)->except(['token', 'slides'])->all(),
            'token' => $this->settings->token(),
            'slides' => collect($s['slides'])->map(fn (array $x) => ['id' => $x['id'], 'title' => $x['title'], 'url' => FileStorage::publicUrl($x['path']), 'seconds' => $x['seconds'], 'transition' => $x['transition'], 'enabled' => $x['enabled'], 'from' => $x['from'], 'to' => $x['to']])->values(),
            'preview' => $this->screen->payload(),
            'size' => ['w' => LobbyScreenSettings::WIDTH, 'h' => LobbyScreenSettings::HEIGHT],
        ];
    }
}
