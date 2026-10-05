<?php

namespace App\Console\Commands;

use App\Services\RegistrationService;
use App\Services\SeatAllocationService;
use Illuminate\Console\Command;

class ReleaseSeats extends Command
{
    protected $signature = 'tedc:seats-release';

    protected $description = 'Give unused allocated seats to the open pool at their release time and promote waiting people';

    public function handle(SeatAllocationService $seats, RegistrationService $registrations): int
    {
        $this->info('Released '.$seats->releaseDue($registrations).' allocation(s).');

        return self::SUCCESS;
    }
}
