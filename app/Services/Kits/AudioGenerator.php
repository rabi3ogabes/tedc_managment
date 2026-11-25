<?php

namespace App\Services\Kits;

use App\Exceptions\BusinessRuleException;
use App\Models\KitAsset;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\FileStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Turns a text into a spoken sound (narration for a slide) with the OpenAI speech API. It needs an OpenAI key; without one the
 * studio says so and the sound can still be uploaded or taken from the library.
 */
class AudioGenerator
{
    public const VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];

    public function __construct(private readonly FileStorage $storage, private readonly AiGateway $gateway) {}

    public function provider(): ?string
    {
        if ($this->gateway->has('audio')) {
            return 'model';
        }

        return filled(config('tedc.kits.openai_key')) ? 'openai' : null;
    }

    public function generate(string $text, TrainingKit $kit, ?User $user, string $voice = 'nova'): KitAsset
    {
        if (! $this->provider()) {
            throw new BusinessRuleException(__('messages.kit.audio_unavailable'), 'audio_unavailable');
        }
        $voice = in_array($voice, self::VOICES, true) ? $voice : 'nova';
        $provider = $this->provider();
        if ($provider === 'model') {
            $spoken = $this->gateway->speech($text, $voice);
            if ($spoken) {
                return $this->store($kit, $user, $text, $spoken['bytes'], 'model', $voice);
            }
            if (blank(config('tedc.kits.openai_key'))) {
                throw new BusinessRuleException(__('messages.kit.audio_failed'), 'audio_failed');
            }
        }
        $response = Http::withToken((string) config('tedc.kits.openai_key'))->timeout(90)->acceptJson()
            ->post('https://api.openai.com/v1/audio/speech', ['model' => config('tedc.kits.openai_tts_model', 'tts-1'), 'voice' => $voice, 'input' => Str::limit($text, 4000, ''), 'response_format' => 'mp3']);
        if (! $response->successful() || $response->body() === '') {
            throw new BusinessRuleException(__('messages.kit.audio_failed'), 'audio_failed');
        }

        return $this->store($kit, $user, $text, $response->body(), 'openai', $voice);
    }

    private function store(TrainingKit $kit, ?User $user, string $text, string $bytes, string $provider, string $voice): KitAsset
    {
        $path = "kits/{$kit->id}/assets/".Str::uuid().'.mp3';
        $this->storage->put('materials', $path, $bytes, 'audio/mpeg');

        return KitAsset::create([
            'kit_id' => $kit->id, 'name' => Str::limit($text, 80, ''), 'mime' => 'audio/mpeg', 'size' => strlen($bytes), 'storage_path' => $path, 'prompt' => $text, 'source' => 'generated',
            'meta' => ['provider' => $provider, 'voice' => $voice, 'bucket' => 'materials', 'duration' => max(1, (int) round(mb_strlen($text) / 14))], 'created_by' => $user?->id,
        ]);
    }
}
