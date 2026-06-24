<?php

namespace DreamFactory\Core\Agents\Models;

use DreamFactory\Core\Agents\Support\AgentAlerts;
use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Core\Utility\Session;

/**
 * A pending request for access an agent does not currently have. Created via the
 * request_access MCP tool (self-service) or the admin API. Resolution (approve/
 * deny) is a human action in the admin UI. On approval the agent's key is rotated
 * (a fresh scoped credential with a new TTL); the admin grants the actual
 * permission by editing the agent's Role in the existing Roles UI.
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
                    // Fresh scoped key + new TTL on approval.
                    $agent->rotateKey();
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
}
