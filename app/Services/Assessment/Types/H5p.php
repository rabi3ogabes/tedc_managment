<?php

namespace App\Services\Assessment\Types;

/** An H5P activity; its own result (score / max) is reported by the player. */
class H5p extends BaseType
{
    public function key(): string
    {
        return 'h5p';
    }

    public function validate(array $payload): array
    {
        if (empty($payload['content_url']) && empty($payload['content_id'])) {
            $this->fail(__('messages.assessment.h5p_required'));
        }

        return ['content_url' => $payload['content_url'] ?? null, 'content_id' => $payload['content_id'] ?? null];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $max = (float) ($answer['max'] ?? 0);

        return $this->result($max > 0 ? (float) ($answer['score'] ?? 0) / $max : 0);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['content_url' => $payload['content_url'], 'content_id' => $payload['content_id']];
    }

    public function correctAnswer(array $payload): mixed
    {
        return null;
    }
}
