<?php

namespace DreamFactory\Core\Agents;

use DreamFactory\Core\Agents\Commands\PruneLedger;
use DreamFactory\Core\Agents\Http\Controllers\AgentSelfServiceController;
use DreamFactory\Core\Agents\Http\Middleware\ActivityLedger;
use DreamFactory\Core\Agents\Http\Middleware\AgentKeyTtl;
use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\Agents\Models\AgentsConfig;
use DreamFactory\Core\Agents\Services\Agents;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/agents.php', 'df-agents');

        $this->app->resolving('df.service', function (ServiceManager $df) {
            $this->addServiceType($df);
        });

        // Register the agent self-service routes during booting() — BEFORE
        // df-core loads its greedy {service}/{resource} catch-all in boot() —
        // so /api/v2/agent/* resolves to our controller, not the service
        // dispatcher (which would RBAC-gate it). Same trick df-mcp-server uses.
        $this->app->booting(function (): void {
            $this->registerSelfServiceRoutes();
        });
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->resolved('df.service')) {
            $this->addServiceType($this->app->make('df.service'));
        }

        // Enforce the agent key TTL on every API request. Pushed onto the same
        // group df-limits uses; it runs after auth_check and self-resolves the
        // agent from the API key, so a non-agent key just passes through.
        Route::aliasMiddleware('df.agent_ttl', AgentKeyTtl::class);
        Route::pushMiddlewareToGroup('df.api', 'df.agent_ttl');

        // The semantic ledger. Pushed AFTER df.agent_ttl so the resolved agent
        // context is available when the row is written.
        Route::aliasMiddleware('df.activity_ledger', ActivityLedger::class);
        Route::pushMiddlewareToGroup('df.api', 'df.activity_ledger');

        // Sponsor rule: agents may not outlive their owner's account. Catches
        // admin-UI deactivation and directory-sync (df-adldap) deactivation
        // alike — both go through the User model.
        User::saved(function (User $user): void {
            if ($user->wasChanged('is_active') && !$user->is_active) {
                Agent::suspendOwnedBy((int) $user->id, 'owner_deactivated');
            }
        });
        User::deleted(function (User $user): void {
            Agent::suspendOwnedBy((int) $user->id, 'owner_deleted');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneLedger::class]);
            $this->scheduleLedgerPrune();
        }
    }

    /**
     * The ledger takes a row for every /api/v2 data-plane request, so prune it
     * daily when a retention window is set (0 keeps everything). Hooked on
     * Schedule resolution, so it only does anything under schedule:run /
     * schedule:list.
     *
     * Deliberately no withoutOverlapping()/onOneServer(): both take a lock
     * through the cache store, and where that store is unreachable (e.g. the
     * redis driver with REDIS_HOST unset) schedule:run silently skips the
     * task. An overlapping or per-node duplicate run just finds nothing left
     * to delete.
     */
    private function scheduleLedgerPrune(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((int) config('df-agents.ledger.retention_days', 90) > 0) {
                $schedule->command('agents:prune-ledger')->daily();
            }
        });
    }

    private function addServiceType(ServiceManager $df): void
    {
        $df->addType(new ServiceType([
            'name'           => 'agents',
            'label'          => 'Agents',
            'description'    => 'AI agent identity and governed access.',
            'group'          => 'Agents',
            'subscription_required' => LicenseLevel::SILVER,
            'singleton'      => true,
            'config_handler' => AgentsConfig::class,
            'factory'        => function ($config) {
                return new Agents($config);
            },
        ]));
    }

    /**
     * Self-scoped endpoints an agent (or the MCP daemon on its behalf) calls
     * with its own API key. Auth-only middleware (no per-service access_check):
     * each handler reads the caller's own identity from the session/API key and
     * only ever exposes or files a request about that caller — never another
     * agent — so the service-RBAC gate is neither needed nor appropriate here.
     */
    private function registerSelfServiceRoutes(): void
    {
        $prefix = config('df.api_route_prefix', 'api') . '/v2/agent';
        Route::prefix($prefix)
            ->middleware(['df.cors', 'df.auth_check'])
            ->group(function () {
                Route::get('catalog', [AgentSelfServiceController::class, 'catalog']);
                Route::post('request_access', [AgentSelfServiceController::class, 'requestAccess']);
                // Agent-to-agent brokering: a domain-owning agent reviews and
                // resolves requests into its own data domain.
                Route::get('inbox', [AgentSelfServiceController::class, 'inbox']);
                Route::post('resolve', [AgentSelfServiceController::class, 'resolve']);
            });
    }
}
