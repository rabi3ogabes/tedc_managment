<?php

namespace App\Payments\Gateways;

use App\Exceptions\BusinessRuleException;
use App\Integrations\IntegrationManager;

/** Chooses the gateway: the Ministry's when it is switched on in Settings → Integrations, the training one otherwise (never in production). */
class GatewayFactory
{
    public function __construct(private readonly IntegrationManager $hub) {}

    public function make(): PaymentGateway
    {
        if ($this->hub->isReady('moe_epay')) {
            $i = $this->hub->get('moe_epay');
            if ($i->driver === 'http') {
                return new MoeEpayGateway($this->hub->settings('moe_epay'));
            }

            return new FakeGateway($this->secret());
        }
        if (app()->environment('production')) {
            throw new BusinessRuleException('The payment gateway is not set up yet.', 'gateway_not_configured');
        }

        return new FakeGateway($this->secret());
    }

    public function secret(): string
    {
        return hash('sha256', 'tedc-fake-gateway|'.config('app.key'));
    }
}
