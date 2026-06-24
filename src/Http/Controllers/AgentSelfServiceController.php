<?php

namespace DreamFactory\Core\Agents\Http\Controllers;

use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\Agents\Models\AgentAccessRequest;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ServiceManager;

/**
 * Endpoints an agent calls with its OWN scoped key (directly, or via the MCP
 * discover_services / request_access tools). Everything here is self-scoped to
 * the caller resolved from the API key — never another agent.
 */
class AgentSelfServiceController extends Controller
{
    /**
     * The role-filtered service catalog for the calling agent. This is literally
     * the existing RBAC data (Session 'role.services', populated by AuthCheck)
     * decoded into services + permitted operations — the "what can I access?"
     * answer, surfaced for agents.
     */
    public function catalog(Request $request)
    {
        $agent = $this->resolveAgent();
        $access = (array)Session::get('role.services');

        $services = [];
        foreach ($access as $a) {
            $serviceId = $a['service_id'] ?? null;
            $name = $serviceId ? ServiceManager::getServiceNameById($serviceId) : '*';
            $services[] = [
                'service'    => $name ?: ('service ' . $serviceId),
                'component'  => ($a['component'] ?? '') ?: '*',
                'operations' => VerbsMask::maskToArray((int)($a['verb_mask'] ?? 0)),
            ];
        }

        // Plain array → Laravel renders JSON (this is a plain controller route,
        // not a DF service dispatch, so don't use ResponseFactory/ServiceResponse).
        return [
            'agent'    => $agent?->name,
            'role_id'  => Session::getRoleId(),
            'services' => $services,
            'hint'     => 'Use the per-service MCP tools (e.g. <service>_get_tables, '
                . '<service>_get_table_schema) to inspect tables within these services.',
        ];
    }

    /**
     * File a pending access request. Creates the record (which fires the
     * df-alerts notification by agent name) and returns the pending status.
     */
    public function requestAccess(Request $request)
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return response()->json(
                ['error' => 'request_access is only available to agent API keys.'],
                403
            );
        }

        $req = AgentAccessRequest::create([
            'agent_id'             => $agent->id,
            'requested_services'   => (array)$request->input('services', $request->input('requested_services', [])),
            'requested_operations' => (array)$request->input('operations', $request->input('requested_operations', [])),
            'note'                 => $request->input('note'),
            'status'               => 'pending',
        ]);

        return [
            'status'     => 'pending',
            'request_id' => $req->id,
            'message'    => 'Access request submitted for human approval. '
                . 'Poll your administrator or re-run discover_services after approval.',
        ];
    }

    /** Resolve the agent behind the request's API key (set on the session by AuthCheck). */
    private function resolveAgent(): ?Agent
    {
        $apiKey = Session::getApiKey();
        return $apiKey ? Agent::whereApiKey($apiKey)->first() : null;
    }
}
