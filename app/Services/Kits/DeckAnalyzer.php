<?php

namespace App\Services\Kits;

use App\Models\KitFile;
use App\Models\TrainingKit;

/**
 * Automatic quality check of a deck: readability, structure, accessibility and alignment with the kit
 * objectives. Findings can be turned into review comments with one click.
 */
class DeckAnalyzer
{
    public function __construct(private readonly KitAi $ai) {}

    /** @return array{score: int, findings: list<array>, stats: array, ai: bool} */
    public function analyze(KitFile $file, TrainingKit $kit, bool $withAi = false): array
    {
        $deck = $file->content ?? DeckModel::blank();
        $slides = $deck['slides'] ?? [];
        $findings = [];
        $add = function (string $severity, string $category, string $title, string $detail, string $suggestion, ?array $slide = null, ?int $index = null, ?string $elementId = null) use (&$findings) {
            $findings[] = [
                'id' => 'f_'.(count($findings) + 1), 'severity' => $severity, 'category' => $category, 'title' => $title, 'detail' => $detail, 'suggestion' => $suggestion,
                'slide_id' => $slide['id'] ?? null, 'slide_index' => $index, 'element_id' => $elementId,
            ];
        };

        $titles = [];
        $totalWords = 0;
        $noNotes = 0;
        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $text = DeckModel::slideText($slide);
            $words = DeckModel::wordCount($text);
            $totalWords += $words;
            $isFramed = in_array($slide['layout'] ?? '', ['title', 'section', 'quote'], true);

            $title = $this->titleOf($slide);
            if (! $title && ! $isFramed && $words > 0) {
                $add('minor', 'content', "Slide {$n} has no title", 'Slides without a clear title are hard to navigate.', 'Add a short, descriptive title.', $slide, $i);
            } elseif ($title) {
                $key = mb_strtolower($title);
                if (isset($titles[$key])) {
                    $add('info', 'content', "Slide {$n} repeats the title of slide {$titles[$key]}", "\"{$title}\"", 'Make titles unique so trainers and learners can tell the slides apart.', $slide, $i);
                }
                $titles[$key] ??= $n;
            }

            if ($words > 90) {
                $add('major', 'design', "Slide {$n} is too dense ({$words} words)", 'Long text slides are read out loud instead of taught.', 'Keep it under 60 words: move detail to the speaker notes or split into two slides.', $slide, $i);
            } elseif ($words > 60) {
                $add('minor', 'design', "Slide {$n} is text-heavy ({$words} words)", 'Learners lose focus on long slides.', 'Shorten bullets or add a visual.', $slide, $i);
            }

            foreach ($slide['elements'] ?? [] as $el) {
                if (($el['type'] ?? null) === 'text') {
                    $this->checkText($el, $slide, $i, $add);
                }
                if (($el['type'] ?? null) === 'image') {
                    if (empty($el['asset_id'])) {
                        $add('minor', 'design', "Slide {$n} has an empty image frame", $el['prompt'] ? "Planned image: {$el['prompt']}" : 'The frame has no picture.', 'Generate or upload the picture, or delete the frame.', $slide, $i, $el['id'] ?? null);
                    } elseif (trim((string) ($el['alt'] ?? '')) === '') {
                        $add('minor', 'accessibility', "Image on slide {$n} has no description", 'Screen-reader users cannot tell what the image shows.', 'Add a short alt text in the image properties.', $slide, $i, $el['id'] ?? null);
                    }
                }
            }

            if (! $isFramed && $words > 25 && trim((string) ($slide['notes'] ?? '')) === '') {
                $noNotes++;
            }
        }

        if ($noNotes > 0 && count($slides) > 0 && $noNotes / count($slides) > 0.5) {
            $add('info', 'content', "{$noNotes} content slides have no speaker notes", 'Trainers deliver the kit better with timing and questions in the notes.', 'Add notes: timing, questions to ask and how to run activities.');
        }

        $this->structure($slides, $kit, $add);
        $this->objectives($slides, $kit, $add);

        $ai = false;
        if ($withAi) {
            foreach ($this->aiFindings($slides, $kit) as $f) {
                $add($f['severity'], $f['category'], $f['title'], $f['detail'], $f['suggestion'], null, null);
                $ai = true;
            }
        }

        $weights = ['critical' => 15, 'major' => 8, 'minor' => 3, 'info' => 1];
        $score = max(0, 100 - array_sum(array_map(fn ($f) => $weights[$f['severity']] ?? 1, $findings)));
        usort($findings, fn ($a, $b) => (array_search($b['severity'], ['info', 'minor', 'major', 'critical']) <=> array_search($a['severity'], ['info', 'minor', 'major', 'critical'])) ?: (($a['slide_index'] ?? 999) <=> ($b['slide_index'] ?? 999)));

        return [
            'score' => $score, 'findings' => $findings, 'ai' => $ai,
            'stats' => ['slides' => count($slides), 'words' => $totalWords, 'avg_words' => count($slides) ? (int) round($totalWords / count($slides)) : 0, 'images' => $this->count($slides, 'image'), 'notes' => count(array_filter($slides, fn ($s) => trim((string) ($s['notes'] ?? '')) !== ''))],
        ];
    }

    private function count(array $slides, string $type): int
    {
        $n = 0;
        foreach ($slides as $s) {
            foreach ($s['elements'] ?? [] as $el) {
                $n += ($el['type'] ?? null) === $type && ! empty($el['asset_id']) ? 1 : 0;
            }
        }

        return $n;
    }

    private function titleOf(array $slide): ?string
    {
        foreach ($slide['elements'] ?? [] as $el) {
            if (($el['type'] ?? null) === 'text' && ($el['y'] ?? 999) < 240 && ($el['paragraphs'][0]['size'] ?? 0) >= 30) {
                return trim((string) ($el['paragraphs'][0]['text'] ?? '')) ?: null;
            }
        }

        return null;
    }

    private function checkText(array $el, array $slide, int $i, callable $add): void
    {
        $n = $i + 1;
        $paras = $el['paragraphs'] ?? [];
        $bg = $el['style']['fill'] ?? ($slide['background']['color'] ?? '#FFFFFF');
        $small = false;
        $lines = 0.0;
        $height = 0.0;
        foreach ($paras as $p) {
            $text = (string) ($p['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $size = (float) ($p['size'] ?? 24);
            $small = $small || $size < 14;
            $perLine = max(4, ($el['w'] - 2 * ($el['style']['padding'] ?? 8)) / ($size * 0.52));
            $l = max(1, ceil(mb_strlen($text) / $perLine));
            $lines += $l;
            $height += $l * $size * ($el['style']['lineHeight'] ?? 1.25);
            $ratio = $this->contrast($p['color'] ?? '#1A1A1A', $bg);
            if ($ratio !== null && $ratio < 3.0) {
                $add('major', 'accessibility', "Low contrast text on slide {$n}", sprintf('Text contrast is %.1f:1 (minimum 4.5:1 for normal text).', $ratio), 'Use a darker text color or a lighter background.', $slide, $i, $el['id'] ?? null);
                break;
            }
            if ($ratio !== null && $ratio < 4.5 && $size < 24) {
                $add('minor', 'accessibility', "Weak contrast on slide {$n}", sprintf('Text contrast is %.1f:1; small text needs at least 4.5:1.', $ratio), 'Increase the contrast of this text.', $slide, $i, $el['id'] ?? null);
                break;
            }
        }
        if ($small) {
            $add('minor', 'design', "Very small text on slide {$n}", 'Text under 14 pt is hard to read from the back of a room.', 'Increase the font size or cut text.', $slide, $i, $el['id'] ?? null);
        }
        if ($lines > 0 && $height + 2 * ($el['style']['padding'] ?? 8) > $el['h'] * 1.08 && $height > 60) {
            $add('major', 'design', "Text may overflow its box on slide {$n}", 'The text looks taller than the frame it sits in.', 'Enlarge the box, shrink the font, or shorten the text.', $slide, $i, $el['id'] ?? null);
        }
    }

    private function structure(array $slides, TrainingKit $kit, callable $add): void
    {
        if (count($slides) === 0) {
            $add('critical', 'content', 'The deck has no slides', 'There is nothing to review yet.', 'Add slides or generate a deck from a prompt.');

            return;
        }
        $all = mb_strtolower(implode("\n", array_map(fn ($s) => $this->titleOf($s) ?? '', $slides)));
        $has = fn (array $words) => collect($words)->contains(fn ($w) => str_contains($all, $w));
        if (! $has(['أهداف', 'objectives', 'outcomes', 'مخرجات'])) {
            $add('major', 'alignment', 'No learning objectives slide', 'Learners should know what they will be able to do.', 'Add a slide with the session objectives after the title slide.');
        }
        if (count($slides) >= 8 && ! $has(['محاور', 'agenda', 'outline', 'المحتوى'])) {
            $add('minor', 'content', 'No agenda slide', 'A longer session needs a roadmap.', 'Add an agenda slide listing the parts of the session.');
        }
        if (count($slides) >= 6 && ! $has(['ملخص', 'خلاصة', 'أبرز ما', 'summary', 'takeaway', 'خاتمة'])) {
            $add('minor', 'content', 'No summary slide', 'Sessions land better with a closing recap.', 'Add key takeaways at the end.');
        }
        if ($kit->duration_hours > 0 && count($slides) > 0) {
            $perSlide = $kit->duration_hours * 60 / count($slides);
            if ($perSlide < 2.5) {
                $add('minor', 'content', 'Too many slides for the session length', sprintf('About %.1f minutes per slide for a %s-hour session.', $perSlide, rtrim(rtrim(number_format($kit->duration_hours, 1), '0'), '.')), 'Merge slides or extend the session.');
            }
        }
    }

    private function objectives(array $slides, TrainingKit $kit, callable $add): void
    {
        $corpus = mb_strtolower(implode("\n", array_map(fn ($s) => DeckModel::slideText($s)."\n".($s['notes'] ?? ''), $slides)));
        foreach ((array) ($kit->objectives ?? []) as $objective) {
            $tokens = array_values(array_filter(preg_split('/[\s\p{P}]+/u', mb_strtolower((string) $objective)) ?: [], fn ($t) => mb_strlen($t) >= 4));
            if (count($tokens) < 2) {
                continue;
            }
            $hits = count(array_filter($tokens, fn ($t) => str_contains($corpus, $t)));
            if ($hits / count($tokens) < 0.4) {
                $add('major', 'alignment', 'An objective is not covered in the deck', (string) $objective, 'Add content, an example or an activity that develops this objective.');
            }
        }
    }

    /** @return list<array{severity: string, category: string, title: string, detail: string, suggestion: string}> */
    private function aiFindings(array $slides, TrainingKit $kit): array
    {
        $outline = collect($slides)->map(fn ($s, $i) => ($i + 1).'. '.($this->titleOf($s) ?? '(no title)').': '.mb_substr(str_replace("\n", ' | ', DeckModel::slideText($s)), 0, 240))->implode("\n");
        $system = 'You are a quality-assurance reviewer of teacher-training material. Review the deck outline against the kit objectives for pedagogy: Bloom level match, interactivity, coherence, clarity, cultural fit for Qatar schools. Return at most 6 specific, actionable findings. Never restate formatting issues (density, contrast) - those are checked separately. Answer in the language of the deck.';
        $prompt = "Kit: {$kit->title_en} / {$kit->title_ar}\nAudience: {$kit->audience}\nObjectives:\n- ".implode("\n- ", (array) ($kit->objectives ?? []))."\n\nDeck outline:\n{$outline}";
        $finding = ['type' => 'object', 'properties' => ['severity' => ['type' => 'string', 'enum' => ['info', 'minor', 'major']], 'category' => ['type' => 'string', 'enum' => ['content', 'alignment', 'language', 'accuracy']], 'title' => ['type' => 'string'], 'detail' => ['type' => 'string'], 'suggestion' => ['type' => 'string']], 'required' => ['severity', 'category', 'title', 'detail', 'suggestion'], 'additionalProperties' => false];
        $data = $this->ai->json($system, $prompt, ['type' => 'object', 'properties' => ['findings' => ['type' => 'array', 'items' => $finding]], 'required' => ['findings'], 'additionalProperties' => false], 3000);

        return array_slice((array) ($data['findings'] ?? []), 0, 6);
    }

    /** WCAG contrast ratio of two hex colors, or null when either is not a plain hex color. */
    private function contrast(?string $a, ?string $b): ?float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        if ($la === null || $lb === null) {
            return null;
        }
        [$hi, $lo] = $la > $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private function luminance(?string $hex): ?float
    {
        if (! is_string($hex) || ! preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return null;
        }
        $c = array_map(fn ($h) => hexdec($h) / 255, array_slice($m, 1));
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
