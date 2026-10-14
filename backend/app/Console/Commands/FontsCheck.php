<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FontsCheck extends Command
{
    protected $signature = 'tedc:fonts-check
        {--strict : Fail (exit 1) when anything is missing}
        {--register-app : Declare the Lusail family in the app\'s pubspec.yaml when its files are there}
        {--web= : The folder of the web fonts}
        {--pdf= : The folder of the PDF fonts}
        {--app= : The folder of the app fonts}
        {--pubspec= : The app\'s pubspec.yaml}';

    protected $description = 'Checks that the licensed Lusail typeface is in place on the web (woff2), in PDFs (ttf) and in the app (ttf + pubspec), and says what is missing';

    private const WEB = ['Lusail-Regular.woff2' => 400, 'Lusail-Medium.woff2' => 500, 'Lusail-Bold.woff2' => 700];

    private const PDF = ['Lusail-Regular.ttf' => 'regular', 'Lusail-Bold.ttf' => 'bold (the regular one stands in without it)'];

    private const APP = ['Lusail-Regular.ttf' => 400, 'Lusail-Medium.ttf' => 500, 'Lusail-Bold.ttf' => 700];

    /** @var list<string> */
    private array $problems = [];

    public function handle(): int
    {
        $web = (string) ($this->option('web') ?: config('tedc.fonts.web'));
        $pdf = (string) ($this->option('pdf') ?: config('tedc.fonts.pdf'));
        $app = (string) ($this->option('app') ?: config('tedc.fonts.app'));
        $pubspec = (string) ($this->option('pubspec') ?: config('tedc.fonts.pubspec'));

        $this->line('<info>Web</info> — '.$web);
        foreach (self::WEB as $file => $weight) {
            $this->report($file, $weight, $this->inspect("{$web}/{$file}", 'woff2'));
        }
        $this->line('<info>PDF (mPDF)</info> — '.$pdf);
        foreach (self::PDF as $file => $what) {
            $this->report($file, $what, $this->inspect("{$pdf}/{$file}", 'ttf'), required: $file === 'Lusail-Regular.ttf');
        }
        $this->line('<info>App</info> — '.$app);
        $appReady = true;
        foreach (self::APP as $file => $weight) {
            $status = $this->inspect("{$app}/{$file}", 'ttf');
            $appReady = $appReady && $status === 'ok';
            $this->report($file, $weight, $status);
        }
        $declared = is_file($pubspec) && str_contains((string) file_get_contents($pubspec), '- family: Lusail');
        if ($this->option('register-app') && $appReady && ! $declared && is_file($pubspec)) {
            $declared = $this->register($pubspec);
        }
        if ($declared) {
            $this->line('  ✓ pubspec.yaml declares the Lusail family');
        } elseif ($appReady) {
            $this->line('  ✗ pubspec.yaml does not declare the Lusail family: run with --register-app');
            $this->problems[] = 'pubspec.yaml does not declare the Lusail family';
        } else {
            $this->line('  · pubspec.yaml: not registered (the app keeps Tajawal; the family is declared once the three files are in place)');
        }

        if ($this->problems === []) {
            $this->info('Lusail is in place everywhere.');
        } else {
            $this->warn(count($this->problems).' thing(s) missing. The site keeps Qatar Sans → Tajawal until the licensed files are in place (docs/FONTS.md).');
        }

        return $this->option('strict') && $this->problems !== [] ? self::FAILURE : self::SUCCESS;
    }

    /** @return string ok | missing | not a WOFF2 file | not a TTF/OTF file | too small */
    private function inspect(string $path, string $kind): string
    {
        if (! is_file($path)) {
            return 'missing';
        }
        $size = (int) filesize($path);
        $head = (string) file_get_contents($path, false, null, 0, 4);
        $valid = $kind === 'woff2' ? $head === 'wOF2' : in_array($head, ["\x00\x01\x00\x00", 'true', 'OTTO'], true);
        if (! $valid) {
            return $kind === 'woff2' ? 'not a WOFF2 file' : 'not a TTF/OTF file';
        }

        return $size < 10_000 ? 'too small' : 'ok';
    }

    private function report(string $file, string|int $what, string $status, bool $required = true): void
    {
        $ok = $status === 'ok';
        $this->line(sprintf('  %s %s (%s)%s', $ok ? '✓' : '✗', $file, $what, $ok ? '' : ' — '.$status));
        if (! $ok && $required) {
            $this->problems[] = "{$file}: {$status}";
        }
    }

    /** Adds the family to pubspec.yaml, before the existing ones so that it comes first. */
    private function register(string $pubspec): bool
    {
        $yaml = (string) file_get_contents($pubspec);
        $block = "    - family: Lusail\n      fonts:\n"
            .implode('', array_map(fn ($file, $weight) => "        - asset: assets/fonts/{$file}\n          weight: {$weight}\n", array_keys(self::APP), self::APP));
        $count = 0;
        $next = preg_replace('/^(  fonts:\n)/m', '$1'.str_replace('$', '\\$', $block), $yaml, 1, $count);
        if ($count !== 1 || $next === null) {
            $this->error('pubspec.yaml has no "fonts:" section to add the family to.');

            return false;
        }
        file_put_contents($pubspec, $next);
        $this->info('The Lusail family is now declared in pubspec.yaml (run flutter pub get).');

        return true;
    }
}
