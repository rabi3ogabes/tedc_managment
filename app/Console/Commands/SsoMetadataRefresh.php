<?php

namespace App\Console\Commands;

use App\Security\Sso\SamlSp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:sso-metadata-refresh')]
#[Description('Refresh the SAML identity provider\'s metadata from its address (same identity provider only): new signing certificates and addresses are applied and audited')]
class SsoMetadataRefresh extends Command
{
    public function handle(SamlSp $saml): int
    {
        $r = $saml->refreshMetadata();
        if (! empty($r['skipped'])) {
            $this->info('No metadata address is set.');

            return self::SUCCESS;
        }
        if (! $r['ok']) {
            $this->error('Metadata refresh failed: '.$r['message']);

            return self::FAILURE;
        }
        $this->info($r['changed'] ? 'Metadata refreshed: changes applied.' : 'Metadata unchanged.');

        return self::SUCCESS;
    }
}
