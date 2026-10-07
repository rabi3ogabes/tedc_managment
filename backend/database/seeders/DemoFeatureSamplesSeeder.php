<?php

namespace Database\Seeders;

use App\Support\DemoGuard;
use Database\Seeders\Samples\SampleAdmin;
use Database\Seeders\Samples\SampleCommerce;
use Database\Seeders\Samples\SampleComms;
use Database\Seeders\Samples\SampleContext;
use Database\Seeders\Samples\SampleLearning;
use Database\Seeders\Samples\SampleMore;
use Database\Seeders\Samples\SampleOps;
use Database\Seeders\Samples\SamplePlanning;
use Database\Seeders\Samples\SampleSocial;
use Illuminate\Database\Seeder;

/**
 * Sample data for every feature, so each screen can be tried: accounts for the newer roles, communities and gamification, plans and needs,
 * attendance and rooms, evaluation and career, library and announcements, orders and the assistant, tickets and integrations.
 *
 * Built on the demo organisation (it does nothing without it) and idempotent: a section that already has its sample is left alone.
 * Parts that cannot be built in a given database are reported in `$problems`, and the rest still run.
 */
class DemoFeatureSamplesSeeder extends Seeder
{
    /** @var list<string> */
    public array $problems = [];

    public function run(): void
    {
        if (! DemoGuard::allowed() || ! $this->context()->user('teacher@tedc.qa')) {
            return;   // production without the demonstration switch, or the demo organisation is not seeded
        }
        $c = $this->context();
        $c->roleAccounts();
        foreach ([SamplePlanning::class, SampleOps::class, SampleLearning::class, SampleComms::class, SampleAdmin::class, SampleMore::class, SampleCommerce::class] as $class) {
            $section = app()->make($class, ['c' => $c]);
            $section->run();
            $this->problems = array_merge($this->problems, $section->problems);
        }
        $social = app()->make(SampleSocial::class, ['c' => $c]);
        try {
            $social->run();
        } catch (\Throwable $e) {
            $this->problems[] = SampleSocial::class.': '.mb_substr($e->getMessage(), 0, 200);
        }
    }

    private ?SampleContext $ctx = null;

    private function context(): SampleContext
    {
        return $this->ctx ??= new SampleContext;
    }
}
