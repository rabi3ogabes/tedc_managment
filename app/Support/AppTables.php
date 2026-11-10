<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own tables. On PostgreSQL `Schema::getTables()` lists every schema, and Supabase keeps its own tables (auth.users,
 * auth.identities, storage.objects …) in the same database: only the tables of the schema in use are the platform's.
 */
final class AppTables
{
    /** @return list<string> */
    public static function names(): array
    {
        $schema = DB::getDriverName() === 'pgsql' ? (DB::selectOne('select current_schema() as s')->s ?? 'public') : null;

        return collect(Schema::getTables())
            ->filter(fn (array $t) => $schema === null || ($t['schema'] ?? $schema) === $schema)
            ->pluck('name')->unique()->values()->all();
    }
}
