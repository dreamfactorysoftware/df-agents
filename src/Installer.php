<?php

namespace DreamFactory\Core\Agents;

use DreamFactory\Core\Models\Service;

/**
 * Install-time provisioning for the Agents module.
 */
class Installer
{
    /** Name of the management service the admin UI talks to. */
    public const MANAGEMENT_SERVICE = 'agents';

    /**
     * Ensure the agents management service instance exists. Idempotent.
     * Without it the admin UI dead-ends on "Could not find a service for agents".
     */
    public static function ensureManagementService(): void
    {
        if (Service::whereName(self::MANAGEMENT_SERVICE)->exists()) {
            return;
        }

        Service::create([
            'name'        => self::MANAGEMENT_SERVICE,
            'label'       => 'Agents',
            'description' => 'AI agent identity and governed access management service.',
            'type'        => 'agents',
            'is_active'   => true,
            'config'      => [],
        ]);
    }
}
