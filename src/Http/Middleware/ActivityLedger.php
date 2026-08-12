<?php

namespace DreamFactory\Core\Agents\Http\Middleware;

use Closure;
use DreamFactory\Core\Agents\Models\AgentActivityLedger;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\TraceId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The semantic ledger recorder. Sits at the HTTP chokepoint AFTER auth and
 * agent resolution: every data-plane operation writes one row — who (agent →
 * owner → role/app), what (verb, service, table), and the outcome (status,
 * row count, duration) — joined to everything else by the platform trace id.
 *
 * ponytail: synchronous single-row insert (~1-2ms local). Queue it behind the
 * Redis worker when sustained volume matters; the write path is one method.
 */
class ActivityLedger
{
    /** Services that are UI/system chatter, not data-plane operations. */
    private const EXCLUDED_SERVICES = ['system', 'api_docs', 'logs', 'user'];

    public function handle(Request $request, Closure $next)
    {
        $startNs = hrtime(true);
        $response = $next($request);

        try {
            $this->record($request, $response, $startNs);
        } catch (\Throwable $e) {
            // The ledger must never break the request path.
            Log::warning('agent_activity_ledger write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function record(Request $request, $response, int $startNs): void
    {
        [$serviceName, $resource] = $this->parsePath($request->path());
        if ($serviceName === null || in_array($serviceName, self::EXCLUDED_SERVICES, true)) {
            return;
        }

        $status = is_object($response) && method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : 0;

        $agent = app()->bound('df.agent') ? app('df.agent') : null;

        AgentActivityLedger::create([
            'trace_id'     => TraceId::get(),
            'agent_id'     => $agent?->id,
            'user_id'      => Session::getCurrentUserId(),
            'role_id'      => Session::getRoleId(),
            'app_id'       => Session::get('app.id'),
            'service_name' => mb_substr($serviceName, 0, 128),
            'resource'     => mb_substr($resource ?? '', 0, 512) ?: null,
            'table_name'   => $this->extractTableName($resource),
            'verb'         => mb_substr($request->method(), 0, 12),
            'status_code'  => $status,
            'row_count'    => $this->countRows($response),
            'duration_ms'  => (int) round((hrtime(true) - $startNs) / 1_000_000),
            'occurred_at'  => now(),
        ]);
    }

    /** @return array{0: ?string, 1: ?string} [service, resource-path] for /api/v2/{service}/{resource...} */
    private function parsePath(string $path): array
    {
        $prefix = trim(config('df.api_route_prefix', 'api'), '/') . '/v2/';
        if (!str_starts_with($path, $prefix)) {
            return [null, null];
        }
        $rest = substr($path, strlen($prefix));
        $segments = explode('/', $rest, 2);

        return [$segments[0] ?: null, $segments[1] ?? null];
    }

    /** Table name for db-service paths (_table/{name} or _schema/{name}). */
    private function extractTableName(?string $resource): ?string
    {
        if ($resource && preg_match('#_(?:table|schema)/([^/?]+)#', $resource, $m)) {
            return mb_substr(urldecode($m[1]), 0, 256);
        }
        return null;
    }

    /**
     * Rows returned/affected when cheaply determinable from a JSON body:
     * {"resource":[...]} => count, single {"id":...} => 1, else null.
     * Bodies over 8MB are not parsed (row_count null, everything else recorded).
     */
    private function countRows($response): ?int
    {
        if (!is_object($response) || !method_exists($response, 'getContent')) {
            return null;
        }
        try {
            $content = $response->getContent();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($content) || $content === '' || strlen($content) > 8_000_000) {
            return null;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['resource']) && is_array($decoded['resource'])) {
            return count($decoded['resource']);
        }
        if (isset($decoded['error'])) {
            return 0;
        }
        if (isset($decoded['id'])) {
            return 1;
        }
        return null;
    }
}
