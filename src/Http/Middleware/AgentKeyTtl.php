<?php

namespace DreamFactory\Core\Agents\Http\Middleware;

use Closure;
use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Http\Middleware\AuthCheck;
use DreamFactory\Core\Utility\ResponseFactory;
use Illuminate\Http\Request;

/**
 * Enforces the short-lived nature of agent API keys — the one thing DF's auth
 * stack doesn't already do. Runs on every /api/v2 request (pushed onto df.api).
 * A non-agent key falls straight through; an agent key past its TTL is rejected.
 *
 * ponytail: this does an indexed lookup per request. If agent traffic ever gets
 * hot, cache api_key -> agent like App::getAppIdByApiKey does. Not worth it yet.
 */
class AgentKeyTtl
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = AuthCheck::getApiKey($request);
        if (!empty($apiKey)) {
            $agent = Agent::whereApiKey($apiKey)->first();
            if ($agent) {
                if ($agent->is_active && $agent->keyExpired()) {
                    return ResponseFactory::sendException(new UnauthorizedException(
                        'Agent API key has expired. The key TTL (' . $agent->key_ttl_hours
                        . 'h) elapsed; request a fresh key via the access flow.'
                    ));
                }
                $this->touch($agent);
            }
        }

        return $next($request);
    }

    /** Record activity for the dashboard, at most ~once a minute, without side-effects. */
    private function touch(Agent $agent): void
    {
        $last = $agent->last_active_at;
        $stale = empty($last)
            || (strtotime((string)$last) < (time() - 60));
        if ($stale) {
            // saveQuietly: do NOT fire the saved hook (which re-syncs the backing app).
            $agent->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }
}
