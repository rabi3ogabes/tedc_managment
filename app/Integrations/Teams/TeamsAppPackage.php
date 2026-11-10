<?php

namespace App\Integrations\Teams;

use RuntimeException;
use ZipArchive;

/**
 * The Teams app package the Ministry's Teams administrator uploads (Teams admin centre → Manage apps → Upload new app): the
 * manifest, its Arabic strings and the two icons, drawn from TEDC's emblem so no image files are needed.
 */
class TeamsAppPackage
{
    public function __construct(private readonly TeamsActivityFeed $feed) {}

    /** Writes the package (a zip with its files at the root, as Teams expects) to $path. */
    public function build(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The package file could not be created.');
        }
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $zip->addFromString('manifest.json', (string) json_encode($this->feed->manifest(createAppId: true), $json));
        $zip->addFromString('ar.json', (string) json_encode($this->feed->localization('ar'), $json));
        $zip->addFromString('color.png', $this->colorIcon());
        $zip->addFromString('outline.png', $this->outlineIcon());
        $zip->close();
    }

    /** The emblem: pediment, three columns and a base (the platform's favicon), on 64 units. @return list<array{0: string, 1: list<float|int>}> */
    private function emblem(): array
    {
        return [['poly', [32, 12, 50, 20, 50, 24, 14, 24, 14, 20]], ['rect', [18, 27, 23, 44]], ['rect', [29.5, 27, 34.5, 44]], ['rect', [41, 27, 46, 44]], ['rect', [14, 46, 50, 51]]];
    }

    /** 192×192, full colour: the emblem in gold on the platform's deep crimson. */
    private function colorIcon(): string
    {
        $k = 12;                       // drawn 4× larger, then reduced, for smooth edges
        $im = imagecreatetruecolor(192 * 4, 192 * 4);
        imagealphablending($im, true);
        imagefill($im, 0, 0, imagecolorallocate($im, 61, 10, 28));
        $stone = imagecolorallocate($im, 162, 148, 117);
        $gold = imagecolorallocate($im, 229, 205, 138);
        foreach ($this->emblem() as $i => [$shape, $p]) {
            $colour = $shape === 'rect' && $i !== 4 ? $gold : $stone;
            $shape === 'poly' ? imagefilledpolygon($im, array_map(fn ($v) => (int) round($v * $k), $p), $colour) : imagefilledrectangle($im, (int) round($p[0] * $k), (int) round($p[1] * $k), (int) round($p[2] * $k) - 1, (int) round($p[3] * $k) - 1, $colour);
        }

        return $this->png($im, 192, 192);
    }

    /** 32×32, transparent, the emblem in white (Teams draws it in the colour of the app bar). */
    private function outlineIcon(): string
    {
        $k = 32;                       // 8× supersampling of a 32 px icon on 64 units: 4 px per unit
        $im = imagecreatetruecolor(32 * 8, 32 * 8);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));   // transparent white, so edges blend with white, never with black
        $white = imagecolorallocatealpha($im, 255, 255, 255, 0);
        foreach ($this->emblem() as [$shape, $p]) {
            $shape === 'poly' ? imagefilledpolygon($im, array_map(fn ($v) => (int) round($v * 4), $p), $white) : imagefilledrectangle($im, (int) round($p[0] * 4), (int) round($p[1] * 4), (int) round($p[2] * 4) - 1, (int) round($p[3] * 4) - 1, $white);
        }

        return $this->png($im, 32, 32, transparent: true);
    }

    private function png(\GdImage $big, int $w, int $h, bool $transparent = false): string
    {
        $out = imagecreatetruecolor($w, $h);
        if ($transparent) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        }
        imagecopyresampled($out, $big, 0, 0, 0, 0, $w, $h, imagesx($big), imagesy($big));
        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }
}
