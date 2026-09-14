<?php

namespace DreamFactory\Core\Agents\Models;

use DreamFactory\Core\Agents\Support\AgentAlerts;
use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\Session;

/**
 * A pending request for access an agent does not currently have. Created via the
 * request_access MCP tool (self-service) or the admin API. Resolution (approve/
 * deny) is a human action in the admin UI. On approval the requested operations
 * are granted on the agent's Role (the agent keeps its key and can immediately
 * retry the work it was blocked on).
 */
class AgentAccessRequest extends BaseSystemModel
{
    protected $table = 'agent_access_requests';

    protected $fillable = [
        'agent_id',
        'requested_services',
        'requested_operations',
        'note',
        'status',
    ];

    protected $guarded = [
        'id',
        'resolved_by_id',
        'resolved_at',
        'created_date',
        'last_modified_date',
    ];

    protected $casts = [
        'id'                   => 'integer',
        'agent_id'             => 'integer',
        'requested_services'   => 'array',
        'requested_operations' => 'array',
    ];

    protected $rules = [
        'agent_id' => 'required|integer',
        'status'   => 'in:pending,approved,denied',
    ];

    /**
     * Set true by the agent-broker resolve path so the grant is clamped to the
     * broker's OWN access — a broker may never grant operations it does not itself
     * hold (see AgentSelfServiceController::resolve). Left false for human-admin
     * approvals, whose verb authority is enforced by normal RBAC on the approving
     * account. Declared as a real property so Eloquent never persists it.
     */
    public bool $clampGrantToSession = false;

    public static function boot()
    {
        parent::boot();

        // Alert on every new request, by agent identity (name, not key id).
        static::created(function (AgentAccessRequest $req) {
            AgentAlerts::accessRequested($req);
            return true;
        });

        // Apply the resolution side-effects exactly once, regardless of whether
        // the decision came from the UI, the REST API, or a script.
        static::updating(function (AgentAccessRequest $req) {
            if ($req->isDirty('status')
                && in_array($req->status, ['approved', 'denied'], true)
                && empty($req->resolved_at)
            ) {
                $req->resolved_at = now();
                $req->resolved_by_id = Session::getCurrentUserId() ?: null;

                if ($req->status === 'approved' && ($agent = $req->agent)) {
                    // Grant the requested operations on the agent's role so its
                    // next request (same key) succeeds. Key rotation is available
                    // via Agent::rotateKey() but kept off here so an in-flight
                    // agent loop isn't broken mid-task.
                    $req->grantRequestedAccess($agent, $req->clampGrantToSession);
                }
                AgentAlerts::accessResolved($req);
            }
            return true;
        });
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /** GET=1, POST=2, PUT=4, PATCH=8, DELETE=16 (VerbsMask bits). */
    private const VERB_BITS = ['GET' => 1, 'HEAD' => 1, 'POST' => 2, 'PUT' => 4, 'PATCH' => 8, 'DELETE' => 16];

    /** Fold a list of operation names ("POST","delete",…) into a VerbsMask. Empty => GET. */
    public static function operationsToMask(array $operations): int
    {
        $mask = 0;
        foreach ($operations as $op) {
            $mask |= (self::VERB_BITS[strtoupper(trim((string)$op))] ?? 0);
        }
        return $mask ?: self::VERB_BITS['GET'];
    }

    /**
     * Add the requested operations to the agent's role for the requested
     * services. Upgrades the agent's existing per-table grants in place (so a
     * read-only agent that asked for write keeps its exact table scope, now with
     * write); falls back to a `_table/*` grant if the role had no access there.
     */
    public function grantRequestedAccess(Agent $agent, bool $clampToSession = false): void
    {
        $roleId = $agent->role_id;
        if (!$roleId) {
            return;
        }

        $requestedMask = self::operationsToMask((array)$this->requested_operations);

        foreach ((array)$this->requested_services as $svcRaw) {
            // Accept either a bare service name ("mysql") or a table-scoped
            // path ("mysql/_table/orders") — agents may request least-privilege
            // table-level access. Grant on the specific table component when
            // given; otherwise broaden to all tables of the service.
            [$svcName, $component] = self::parseServiceTarget((string)$svcRaw);
            $service = Service::whereName($svcName)->first();
            if (!$service) {
                continue;
            }

            // When an agent brokered this approval, never grant an operation the
            // broker does not itself hold on this exact target. getServicePermissions
            // returns the approver's own verb mask (all verbs for a sysadmin, so the
            // human path is unchanged), so ANDing clamps an over-broad request down.
            $verbMask = $requestedMask;
            if ($clampToSession) {
                $verbMask &= Session::getServicePermissions($svcName, $component ?? '_table/*');
                if ($verbMask === 0) {
                    continue; // broker holds nothing here — grant nothing
                }
            }

            // A bare service name means "all tables": target the _table/* row
            // explicitly. OR-ing into whatever rows already exist never widens
            // access to a table the role has no row for (that was a silent no-op).
            $target = $component ?? '_table/*';
            $query = RoleServiceAccess::where('role_id', $roleId)
                ->where('service_id', $service->id)
                ->where('component', $target);
            $rows = $query->get();
            if ($rows->isEmpty()) {
                RoleServiceAccess::create([
                    'role_id'        => $roleId,
                    'service_id'     => $service->id,
                    'component'      => $target,
                    'verb_mask'      => $verbMask,
                    'requestor_mask' => 3, // API | SCRIPT
                    'filters'        => [],
                    'filter_op'      => 'AND',
                ]);
            } else {
                foreach ($rows as $row) {
                    $row->verb_mask = ((int)$row->verb_mask) | $verbMask;
                    $row->save();
                }
            }
        }

        // The role's permission set is cached; drop it so the grant is live now.
        \Cache::forget('role:' . $roleId);
    }

    /**
     * Split a requested service target into [serviceName, component]. Returns a
     * null component for a bare service name (grant applies to all its tables).
     *   "mysql"                  => ["mysql", null]
     *   "mysql/_table/orders"    => ["mysql", "_table/orders/*"]
     *   "mysql/_table/orders/*"  => ["mysql", "_table/orders/*"]
     */
    /** Service ids referenced by this request's requested_services (for broker routing). */
    public function requestedServiceIds(): array
    {
        $ids = [];
        foreach ((array)$this->requested_services as $raw) {
            [$name] = self::parseServiceTarget((string)$raw);
            $svc = Service::whereName($name)->first();
            if ($svc) {
                $ids[] = (int)$svc->id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function parseServiceTarget(string $raw): array
    {
        $raw = trim($raw, " /");
        $parts = explode('/', $raw, 2);
        $svcName = $parts[0];
        if (!isset($parts[1]) || $parts[1] === '') {
            return [$svcName, null];
        }
        $rem = trim($parts[1], '/');
        if (strncmp($rem, '_table/', 7) === 0) {
            $table = explode('/', substr($rem, 7))[0];
            if ($table !== '' && $table !== '*') {
                return [$svcName, '_table/' . $table . '/*'];
            }
        }
        return [$svcName, null];
    }
}
