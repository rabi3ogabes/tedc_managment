<?php

namespace App\Support;

/**
 * The single shared password of the demonstration accounts the seeders create for local development and staging.
 * It must never reach a live system: production never runs demo mode (see DemoGuard), deployments never set passwords,
 * and the web and mobile clients only learn it from the server while demo mode is on.
 */
final class DemoCredentials
{
    public const PASSWORD = 'Tedc@2026!';
}
