<?php

namespace App\Services\Content;

use App\Exceptions\BusinessRuleException;
use App\Models\ContentPackage;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Reads an e-learning package (SCORM 1.2 / 2004, cmi5, xAPI / TinCan, H5P, HTML5, Common Cartridge) safely: no path
 * leaves the package folder, executables are refused, size and entry counts are limited. Files go to private storage
 * and are served only through the signed same-origin proxy.
 */
class ContentPackageService
{
    public const MAX_ENTRIES = 4000;

    public const MAX_UNCOMPRESSED = 600 * 1024 * 1024;

    private const DENIED = ['php', 'phtml', 'phar', 'exe', 'dll', 'sh', 'bat', 'cmd', 'jar', 'com', 'msi', 'cgi', 'pl', 'py'];

    public function __construct(private readonly FileStorage $storage) {}

    /** @param  string  $zipPath  a local file path */
    public function ingest(string $zipPath, User $by, ?string $title = null): ContentPackage
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new BusinessRuleException(__('messages.package.not_zip'), 'package_not_zip');
        }
        try {
            $names = $this->check($zip);
            [$standard, $meta] = $this->detect($zip, $names);
            $id = (string) Str::uuid();
            $root = "packages/{$id}";
            $size = 0;
            foreach ($names as $i => $name) {
                $bytes = (string) $zip->getFromIndex($i);
                $size += strlen($bytes);
                $this->storage->put('packages', "{$root}/{$name}", $bytes, Mime::of($name));
            }

            return ContentPackage::create(['id' => $id, 'standard' => $standard, 'title' => $title ?: ($meta['title'] ?? 'Package'), 'version' => $meta['version'] ?? null, 'storage_root' => $root, 'manifest' => $meta['manifest'] ?? null, 'entry_points' => $meta['entry_points'] ?? [], 'size' => $size, 'uploaded_by' => $by->id, 'status' => 'ready']);
        } finally {
            $zip->close();
        }
    }

    /** Validates every entry; returns the usable file names by zip index. @return array<int, string> */
    private function check(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new BusinessRuleException(__('messages.package.too_many_files'), 'package_too_big');
        }
        $names = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (str_ends_with($name, '/')) {
                continue;
            }
            // Zip-slip: no absolute paths, no "..", no drive letters, no NUL.
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[A-Za-z]:#', $name) || str_contains($name, "\0")) {
                throw new BusinessRuleException(__('messages.package.unsafe_path', ['name' => $name]), 'package_unsafe');
            }
            if (str_starts_with(basename($name), '.') || str_starts_with($name, '__MACOSX/')) {
                continue;
            }
            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::DENIED, true)) {
                throw new BusinessRuleException(__('messages.package.denied_type', ['name' => $name]), 'package_denied');
            }
            $total += (int) $stat['size'];
            if ($total > self::MAX_UNCOMPRESSED) {
                throw new BusinessRuleException(__('messages.package.too_big'), 'package_too_big');
            }
            $names[$i] = $name;
        }
        if ($names === []) {
            throw new BusinessRuleException(__('messages.package.empty'), 'package_empty');
        }

        return $names;
    }

    /** @param  array<int, string>  $names  @return array{0: string, 1: array<string, mixed>} */
    private function detect(ZipArchive $zip, array $names): array
    {
        $has = fn (string $f) => in_array($f, array_map('strtolower', $names), true);
        $read = fn (string $f) => (string) $zip->getFromName(array_values(array_filter($names, fn ($n) => strtolower($n) === $f))[0] ?? $f);

        if ($has('cmi5.xml')) {
            return ['cmi5', ManifestParser::cmi5($read('cmi5.xml'))];
        }
        if ($has('tincan.xml')) {
            return ['xapi', ManifestParser::tincan($read('tincan.xml'))];
        }
        if ($has('h5p.json')) {
            $j = json_decode($read('h5p.json'), true) ?: [];

            return ['h5p', ['title' => $j['title'] ?? 'H5P', 'manifest' => $j, 'entry_points' => [['id' => 'h5p', 'title' => $j['title'] ?? 'H5P', 'href' => 'h5p.json', 'type' => 'h5p']]]];
        }
        if ($has('imsmanifest.xml')) {
            $xml = $read('imsmanifest.xml');
            $kind = ManifestParser::kind($xml);

            return [$kind, ManifestParser::ims($xml, $kind)];
        }
        if ($has('index.html')) {
            return ['html5', ['title' => 'HTML5', 'entry_points' => [['id' => 'index', 'title' => 'index.html', 'href' => 'index.html', 'type' => 'sco']]]];
        }

        throw new BusinessRuleException(__('messages.package.unknown'), 'package_unknown');
    }

    public function delete(ContentPackage $package): void
    {
        $package->delete();
    }
}
