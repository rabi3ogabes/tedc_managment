<?php

namespace App\Console\Commands;

use App\Payments\MoeEpayCertification;
use Illuminate\Console\Command;

class PaymentsCertify extends Command
{
    protected $signature = 'tedc:payments-certify';

    protected $description = 'Runs the certification plan of the Ministry payment gateway against the mock (pretend orders, rolled back; never in production)';

    public function handle(MoeEpayCertification $certification): int
    {
        if (app()->isProduction()) {
            $this->error('The certification plan works on pretend orders and does not run in production.');

            return self::FAILURE;
        }
        $result = $certification->run();
        foreach ($result['cases'] as $case) {
            $this->line(($case['ok'] ? '<info>PASS</info> ' : '<error>FAIL</error> ').$case['id'].' — '.$case['title']['en']);
            foreach ($case['steps'] as $step) {
                $this->line('       '.($step['ok'] ? '✓' : '✗').' '.$step['step'].($step['ok'] ? '' : ' — '.$step['detail']));
            }
        }
        $passed = count(array_filter($result['cases'], fn ($c) => $c['ok']));
        $this->line("{$passed} of ".count($result['cases']).' cases passed.');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
