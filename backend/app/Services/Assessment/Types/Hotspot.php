<?php

namespace App\Services\Assessment\Types;

/** Click on the right place of an image. Areas use percentages (0–100) of the image size. */
class Hotspot extends BaseType
{
    public function key(): string
    {
        return 'hotspot';
    }

    public function validate(array $payload): array
    {
        $areas = [];
        foreach ($payload['areas'] ?? [] as $i => $a) {
            $shape = $a['shape'] ?? 'rect';
            if (! in_array($shape, ['rect', 'circle'], true) || ! isset($a['x'], $a['y']) || ($shape === 'rect' && ! isset($a['w'], $a['h'])) || ($shape === 'circle' && ! isset($a['r']))) {
                $this->fail(__('messages.assessment.areas_invalid'));
            }
            $areas[] = ['id' => (string) ($a['id'] ?? 'a'.($i + 1)), 'shape' => $shape, 'x' => (float) $a['x'], 'y' => (float) $a['y'], 'w' => (float) ($a['w'] ?? 0), 'h' => (float) ($a['h'] ?? 0), 'r' => (float) ($a['r'] ?? 0), 'correct' => (bool) ($a['correct'] ?? false)];
        }
        if (empty($payload['image']) || ! array_filter($areas, fn ($a) => $a['correct'])) {
            $this->fail(__('messages.assessment.areas_invalid'));
        }

        return ['image' => (string) $payload['image'], 'areas' => $areas];
    }

    public function grade(array $payload, mixed $answer): array
    {
        $x = (float) ($answer['x'] ?? -1);
        $y = (float) ($answer['y'] ?? -1);
        foreach ($payload['areas'] as $a) {
            if (! $a['correct']) {
                continue;
            }
            $inside = $a['shape'] === 'rect' ? ($x >= $a['x'] && $x <= $a['x'] + $a['w'] && $y >= $a['y'] && $y <= $a['y'] + $a['h']) : (($x - $a['x']) ** 2 + ($y - $a['y']) ** 2 <= $a['r'] ** 2);
            if ($inside) {
                return $this->result(1);
            }
        }

        return $this->result(0);
    }

    public function publicPayload(array $payload, bool $shuffle): array
    {
        return ['image' => $payload['image']];
    }

    public function correctAnswer(array $payload): mixed
    {
        $a = collect($payload['areas'])->firstWhere('correct', true);

        return $a ? ['x' => $a['shape'] === 'rect' ? $a['x'] + $a['w'] / 2 : $a['x'], 'y' => $a['shape'] === 'rect' ? $a['y'] + $a['h'] / 2 : $a['y']] : null;
    }
}
