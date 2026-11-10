<?php

namespace App\Migration;

use App\Migration\Importers\AttendanceSummaryImporter;
use App\Migration\Importers\CertificatesImporter;
use App\Migration\Importers\EmployeesImporter;
use App\Migration\Importers\Importer;
use App\Migration\Importers\PdActivitiesImporter;
use App\Migration\Importers\ProgramsImporter;
use App\Migration\Importers\RegistrationsImporter;
use App\Migration\Importers\TrainersImporter;

/** The kinds of legacy data that can be brought in, in the order they should be imported (people and programs before what refers to them). */
class ImporterRegistry
{
    public const ORDER = ['employees', 'trainers', 'programs', 'registrations', 'attendance', 'certificates', 'pd'];

    /** @return array<string, Importer> */
    public static function all(): array
    {
        return [
            'employees' => new EmployeesImporter, 'trainers' => new TrainersImporter, 'programs' => new ProgramsImporter, 'registrations' => new RegistrationsImporter,
            'attendance' => new AttendanceSummaryImporter, 'certificates' => new CertificatesImporter, 'pd' => new PdActivitiesImporter,
        ];
    }

    public static function get(string $kind): Importer
    {
        return self::all()[$kind] ?? abort(404);
    }
}
