<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

#[Fillable(['key', 'driver', 'config', 'enabled', 'health', 'latency_ms', 'last_check_at', 'last_sync_at', 'last_error', 'failures', 'open_until'])]
class Integration extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $casts = ['enabled' => 'boolean', 'last_check_at' => 'datetime', 'last_sync_at' => 'datetime', 'open_until' => 'datetime', 'failures' => 'integer'];

    /** The settings, decrypted. @return array<string, mixed> */
    public function settings(): array
    {
        if (! $this->config) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($this->config), true) ?: [];
        } catch (Throwable) {
            return [];   // APP_KEY changed: the settings must be entered again
        }
    }

    /** @param  array<string, mixed>  $settings */
    public function putSettings(array $settings): void
    {
        $this->config = $settings ? Crypt::encryptString(json_encode($settings)) : null;
    }
}
