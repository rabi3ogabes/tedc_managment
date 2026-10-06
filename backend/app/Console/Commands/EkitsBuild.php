<?php

namespace App\Console\Commands;

use App\Learning\EKits\EKitBuilder;
use App\Learning\EKits\EKitSource;
use App\Learning\EKits\ScormExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:ekits-build {--rebuild : Replace the lessons of kits that were built before} {--export= : Folder to write the SCORM 2004 packages (Arabic and English) to} {--only= : Build one kit by code}')]
#[Description('Publish the e-kits in content/e-kits as e-courses and export them as SCORM 2004 packages')]
class EkitsBuild extends Command
{
    public function handle(EKitBuilder $builder, ScormExporter $scorm): int
    {
        $kits = EKitSource::all();
        if ($only = $this->option('only')) {
            $kits = array_values(array_filter($kits, fn ($k) => $k['code'] === $only));
            if (! $kits) {
                $this->error("No e-kit with code {$only}.");

                return self::FAILURE;
            }
        }
        foreach ($kits as $kit) {
            $r = $builder->build($kit, (bool) $this->option('rebuild'));
            $this->line(($r['created'] ? 'built   ' : 'kept    ').$kit['code'].'  → program '.$r['program']->id);
            if ($dir = $this->option('export')) {
                @mkdir($dir, 0775, true);
                foreach (['ar', 'en'] as $lang) {
                    $file = rtrim($dir, '/').'/'.$kit['code'].'-'.$lang.'-scorm2004.zip';
                    file_put_contents($file, $scorm->export($kit, $lang));
                    $this->line('exported '.$file);
                }
            }
        }

        return self::SUCCESS;
    }
}
