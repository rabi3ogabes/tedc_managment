<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The application is called «شاهين - مشروع بوابة التدريب» (Shaheen – Training Portal Project). A name saved in Settings → Identity is the
 * administrator's choice and is left alone; one that is still the old default is moved to the new name.
 */
return new class extends Migration
{
    private const OLD = ['ar' => 'مركز التدريب والتطوير', 'en' => 'Training & Development Center'];

    private const NEW = ['ar' => 'شاهين - مشروع بوابة التدريب', 'en' => 'Shaheen – Training Portal Project'];

    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'theme')->first();
        if (! $row) {
            return;
        }
        $theme = json_decode((string) $row->value, true);
        if (! is_array($theme) || ! isset($theme['identity']) || ! is_array($theme['identity'])) {
            return;
        }
        foreach (['ar', 'en'] as $l) {
            if (($theme['identity']["name_$l"] ?? null) === self::OLD[$l]) {
                $theme['identity']["name_$l"] = self::NEW[$l];
            }
        }
        DB::table('site_settings')->where('key', 'theme')->update(['value' => json_encode($theme, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public function down(): void
    {
        // The new name stays; an administrator can rename it in Settings.
    }
};
