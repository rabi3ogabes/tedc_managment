<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Http\Resources\KitFileResource;
use App\Models\KitAsset;
use App\Models\KitFile;
use App\Models\KitGeneration;
use App\Models\TrainingKit;
use App\Services\FileStorage;
use App\Services\Kits\DeckGenerator;
use App\Services\Kits\ImageGenerator;
use App\Services\Kits\KitAccess;
use App\Services\Kits\KitAi;
use App\Services\Kits\KitFiles;
use App\Services\Kits\StoryboardGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AI generation for the studio: decks, slides, images, video storyboards and text rewriting. */
class KitAiController extends KitBaseController
{
    public function __construct(
        private readonly KitAi $ai,
        private readonly DeckGenerator $decks,
        private readonly ImageGenerator $images,
        private readonly StoryboardGenerator $storyboards,
        private readonly FileStorage $storage,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => ['ai' => $this->ai->enabled(), 'image_provider' => $this->images->provider(), 'video' => 'browser']]);
    }

    /** Builds a complete deck from a prompt and saves it as a new editable presentation. */
    public function deck(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->generatable($kit);
        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:300'], 'audience' => ['nullable', 'string', 'max:255'],
            'objectives' => ['nullable', 'array', 'max:15'], 'objectives.*' => ['string', 'max:500'],
            'slide_count' => ['nullable', 'integer', 'min:4', 'max:40'], 'language' => ['nullable', Rule::in(['ar', 'en'])], 'tone' => ['nullable', 'string', 'max:120'],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:100'], 'activities' => ['sometimes', 'boolean'], 'quiz' => ['sometimes', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'], 'name' => ['nullable', 'string', 'max:255'], 'category' => ['nullable', Rule::in(KitFile::CATEGORIES)],
        ]);
        $params = $this->withKitDefaults($data, $kit);

        $result = $this->decks->generate($params);
        $file = app(KitFiles::class)->createDeck($kit, $this->user(), $data['name'] ?? $params['topic'], $result['deck'], 'generated', $data['category'] ?? null);
        KitGeneration::create(['kit_id' => $kit->id, 'user_id' => $this->user()->id, 'type' => 'deck', 'prompt' => $params['topic'], 'params' => $params, 'result' => ['file_id' => $file->id, 'slides' => count($result['deck']['slides'])], 'provider' => $result['provider']]);

        return response()->json(['data' => [
            'file' => new KitFileResource($file->load(['uploader', 'updater'])), 'provider' => $result['provider'], 'ai_available' => $this->ai->enabled(), 'slides' => count($result['deck']['slides']),
        ]], 201);
    }

    /** New slides for an open deck (returned, not saved: the editor inserts them). */
    public function slides(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->generatable($kit);
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:300'], 'count' => ['nullable', 'integer', 'min:1', 'max:12'], 'instructions' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', Rule::in(['ar', 'en'])], 'activities' => ['sometimes', 'boolean'],
        ]);
        $count = (int) ($data['count'] ?? 3);
        $language = $data['language'] ?? ($file->content['theme']['dir'] ?? 'rtl') === 'ltr' ? 'en' : 'ar';
        $result = $this->decks->generate($this->withKitDefaults([
            'topic' => $data['topic'], 'slide_count' => max(4, $count + 3), 'language' => $language, 'instructions' => $data['instructions'] ?? null, 'activities' => $data['activities'] ?? false, 'quiz' => false,
        ], $kit));

        // Drop the framing slides (title / agenda / summary) so only the requested content comes back.
        $slides = array_values(array_filter($result['deck']['slides'], fn ($s) => ! in_array($s['layout'], ['title', 'agenda', 'summary', 'assessment'], true)));
        $slides = array_slice($slides ?: $result['deck']['slides'], 0, $count);
        KitGeneration::create(['kit_id' => $kit->id, 'user_id' => $this->user()->id, 'type' => 'deck', 'prompt' => $data['topic'], 'params' => $data, 'result' => ['slides' => count($slides)], 'provider' => $result['provider']]);

        return response()->json(['data' => ['slides' => $slides, 'provider' => $result['provider']]]);
    }

    public function image(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->generatable($kit);
        $data = $request->validate(['prompt' => ['required', 'string', 'min:3', 'max:1000'], 'aspect' => ['nullable', Rule::in(['16:9', '4:3', '1:1'])], 'style' => ['nullable', 'string', 'max:200']]);
        $asset = $this->images->generate($data['prompt'], $kit, $this->user(), $data);

        return response()->json(['data' => $this->assetRow($asset)], 201);
    }

    /** Plans a short explainer video; the browser renders the frames into a video file. */
    public function storyboard(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->generatable($kit);
        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:300'], 'audience' => ['nullable', 'string', 'max:255'], 'scenes' => ['nullable', 'integer', 'min:3', 'max:8'],
            'seconds' => ['nullable', 'integer', 'min:20', 'max:180'], 'language' => ['nullable', Rule::in(['ar', 'en'])], 'style' => ['nullable', 'string', 'max:200'], 'instructions' => ['nullable', 'string', 'max:2000'],
        ]);
        $params = $this->withKitDefaults($data, $kit);
        $plan = $this->storyboards->plan($params);
        KitGeneration::create(['kit_id' => $kit->id, 'user_id' => $this->user()->id, 'type' => 'storyboard', 'prompt' => $params['topic'], 'params' => $params, 'result' => ['scenes' => count($plan['scenes'])], 'provider' => $plan['provider']]);

        return response()->json(['data' => $plan + ['ai_available' => $this->ai->enabled()]]);
    }

    /** Improves, shortens, expands or translates a piece of text. */
    public function rewrite(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->editable($kit);
        abort_unless($this->user()->hasPermission('kits.generate') || $this->user()->hasPermission('kits.review'), 403);
        $data = $request->validate(['text' => ['required', 'string', 'max:4000'], 'mode' => ['required', Rule::in(['improve', 'shorten', 'expand', 'simplify', 'formal', 'translate_en', 'translate_ar'])]]);

        $instruction = [
            'improve' => 'Improve clarity and flow while keeping the meaning and the language.', 'shorten' => 'Make it about half as long, keeping the key points.',
            'expand' => 'Expand with one concrete classroom example, keeping the language.', 'simplify' => 'Rewrite in simpler words for busy teachers.',
            'formal' => 'Rewrite in formal wording suitable for official government communication.', 'translate_en' => 'Translate to English.', 'translate_ar' => 'Translate to Modern Standard Arabic (Western digits).',
        ][$data['mode']];
        $result = $this->ai->json('You edit text on training slides. Return only the rewritten text, keeping line breaks. Never add commentary.', "{$instruction}\n\nTEXT:\n{$data['text']}",
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'], 'additionalProperties' => false], 2000);

        return response()->json(['data' => ['text' => $result['text'] ?? $data['text'], 'changed' => isset($result['text']), 'ai_available' => $this->ai->enabled()]]);
    }

    private function generatable(TrainingKit $kit): void
    {
        $this->viewable($kit);
        abort_unless(KitAccess::canGenerate($this->user(), $kit), 403, in_array($kit->status, TrainingKit::EDITABLE, true) ? __('auth.forbidden') : __('messages.kit.locked'));
    }

    private function withKitDefaults(array $data, TrainingKit $kit): array
    {
        $isAr = ($data['language'] ?? null) !== 'en';

        return array_filter($data + ['topic' => null], fn ($v) => $v !== null) + [
            'topic' => $data['topic'] ?? ($isAr ? $kit->title_ar : $kit->title_en) ?: $kit->title_ar,
            'audience' => $data['audience'] ?? $kit->audience,
            'objectives' => $data['objectives'] ?? array_values((array) $kit->objectives),
            'duration_hours' => $data['duration_hours'] ?? ($kit->duration_hours ?: null),
        ] + ['topic' => $kit->title_ar];
    }

    public function assetRow(KitAsset $asset): array
    {
        return [
            'id' => $asset->id, 'name' => $asset->name, 'mime' => $asset->mime, 'width' => $asset->width, 'height' => $asset->height, 'prompt' => $asset->prompt, 'source' => $asset->source,
            'provider' => $asset->meta['provider'] ?? null, 'url' => $this->storage->temporaryUrl('documents', $asset->storage_path, (int) config('tedc.kits.asset_url_ttl')),
        ];
    }
}
