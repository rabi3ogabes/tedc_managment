<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Another LMS registered to launch TEDC as an LTI tool (1.3, or 1.1 with a consumer key and secret). */
#[Fillable(['name', 'version', 'issuer', 'client_id', 'deployment_ids', 'auth_login_url', 'auth_token_url', 'jwks_url', 'public_key', 'consumer_key', 'consumer_secret', 'status', 'settings'])]
#[Hidden(['consumer_secret'])]
class LtiPlatform extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['deployment_ids' => 'array', 'settings' => 'array', 'consumer_secret' => 'encrypted'];
    }

    public function active(): bool
    {
        return $this->status === 'active';
    }

    /** @return list<string> */
    public function programIds(): array
    {
        return array_values(array_filter((array) ($this->settings['program_ids'] ?? [])));
    }

    /** @return list<string> e-mail domains whose accounts a launch may sign in to directly */
    public function trustedDomains(): array
    {
        return array_values(array_map(fn ($d) => strtolower(ltrim(trim((string) $d), '@')), array_filter((array) ($this->settings['trusted_domains'] ?? []))));
    }

    /** The web origins the platform's pages come from (the launch page may be shown inside them). @return list<string> */
    public function origins(): array
    {
        $out = [];
        foreach ([$this->issuer, $this->auth_login_url] as $url) {
            $p = $url ? parse_url($url) : null;
            if ($p && isset($p['scheme'], $p['host']) && in_array($p['scheme'], ['https', 'http'], true)) {
                $out[] = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
            }
        }

        return array_values(array_unique($out));
    }
}
