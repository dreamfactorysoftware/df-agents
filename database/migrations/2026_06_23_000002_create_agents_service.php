<?php

use DreamFactory\Core\Agents\Installer;
use DreamFactory\Core\Models\Service;
use Illuminate\Database\Migrations\Migration;

/**
 * Provision the "agents" management service so the admin UI works on a fresh
 * install. Idempotent via Installer::ensureManagementService().
 */
return new class extends Migration
{
    public function up(): void
    {
        Installer::ensureManagementService();
    }

    public function down(): void
    {
        Service::whereName(Installer::MANAGEMENT_SERVICE)
            ->where('type', 'agents')
            ->delete();
    }
};
