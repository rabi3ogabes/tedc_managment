<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'version', 'client_id', 'deployment_id', 'login_url', 'launch_url', 'jwks_url', 'public_key', 'deep_link_url', 'consumer_key', 'consumer_secret', 'custom', 'privacy', 'supports_ags', 'is_active'])]
class LtiTool extends Model
{
    use HasUuids;

    protected $casts = ['custom' => 'array', 'privacy' => 'array', 'supports_ags' => 'boolean', 'is_active' => 'boolean', 'consumer_secret' => 'encrypted'];
}
