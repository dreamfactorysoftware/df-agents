<?php

namespace DreamFactory\Core\Agents\Http\Controllers;

use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\Agents\Models\AgentAccessRequest;
use DreamFactory\Core\Agents\Support\AgentAlerts;
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

    /**
     * Broker inbox: pending requests from OTHER agents for a service THIS agent
     * owns (i.e. its role grants access to that service). This is the
     * agent-to-agent governance surface — a domain-owning agent reviews and
     * decides access requests into its own data domain, exactly as a human admin
     * does, instead of every request escalating to a person.
     */
    public function inbox(Request $request)
    {
        $broker = $this->resolveAgent();
        if (!$broker) {
            return response()->json(['error' => 'inbox is only available to agent API keys.'], 403);
        }
        $mine = $this->accessibleServiceIds();           // null => all services
        $out = [];
        foreach (AgentAccessRequest::where('status', 'pending')
                     ->where('agent_id', '!=', $broker->id)->get() as $req) {
            if ($this->brokerCovers($mine, $req->requestedServiceIds())) {
                $out[] = [
                    'request_id' => $req->id,
                    'from_agent' => optional($req->agent)->name,
                    'services'   => (array)$req->requested_services,
                    'operations' => (array)$req->requested_operations,
                    'note'       => $req->note,
                ];
            }
        }
        return ['broker' => $broker->name, 'requests' => $out];
    }

    /**
     * Broker decision: the calling agent approves/denies a request into a domain
     * it owns. Authorized only if the broker's role actually grants the requested
     * service(s). On approve, the existing model hook grants the requester the
     * scoped access and fires the alert.
     */
    public function resolve(Request $request)
    {
        $broker = $this->resolveAgent();
        if (!$broker) {
            return response()->json(['error' => 'resolve is only available to agent API keys.'], 403);
        }
        $req = AgentAccessRequest::find($request->input('request_id'));
        if (!$req || $req->status !== 'pending') {
            return response()->json(['error' => 'No pending request with that id.'], 404);
        }
        if ($req->agent_id === $broker->id) {
            return response()->json(['error' => 'An agent cannot resolve its own request.'], 403);
        }
        if (!$this->brokerCovers($this->accessibleServiceIds(), $req->requestedServiceIds())) {
            return response()->json(
                ['error' => 'You do not own the requested service domain, so you cannot broker this request.'],
                403
            );
        }

        $decision = strtolower((string)$request->input('decision'));

        // Verb-level authorization. brokerCovers() only proves the broker's role
        // touches the requested SERVICES; it says nothing about operations. Without
        // this gate a read-only broker could approve POST/PUT/DELETE and escalate
        // another agent above its own privileges. Require the broker to actually
        // hold every requested operation on every requested target before approving.
        if ($decision === 'approve') {
            $requestedMask = AgentAccessRequest::operationsToMask((array)$req->requested_operations);
            foreach ((array)$req->requested_services as $svcRaw) {
                [$svcName, $component] = AgentAccessRequest::parseServiceTarget((string)$svcRaw);
                $brokerMask = Session::getServicePermissions($svcName, $component ?? '_table/*');
                if ($requestedMask & ~$brokerMask) {
                    return response()->json([
                        'error' => "You cannot grant operations you do not hold on '{$svcName}'. "
                            . 'A broker may only approve access within its own permissions.',
                    ], 403);
                }
            }
            // Belt-and-suspenders: also clamp the grant itself to the broker's mask.
            $req->clampGrantToSession = true;
        }

        $req->status = $decision === 'approve' ? 'approved' : 'denied';
        $note = trim((string)$request->input('note'));
        $req->note = trim(($req->note ? $req->note . ' · ' : '')
            . 'brokered by ' . $broker->name . ($note ? ': ' . $note : ''));
        $req->save();  // model hook applies the grant (on approve) + the resolved alert

        AgentAlerts::agentAction($broker->name,
            ":handshake: *df-agents* — agent `{$broker->name}` *{$req->status}* "
            . "`" . (optional($req->agent)->name ?? 'agent') . "`'s request to its data domain",
            ['broker' => $broker->name, 'request_id' => $req->id, 'decision' => $req->status]);

        return [
            'status'     => $req->status,
            'broker'     => $broker->name,
            'request_id' => $req->id,
            'message'    => $req->status === 'approved'
                ? 'Access granted to the requesting agent on your domain.'
                : 'Request denied.',
        ];
    }

    /** Service ids the calling agent's role grants. null means "all services". */
    private function accessibleServiceIds(): ?array
    {
        $ids = [];
        foreach ((array)Session::get('role.services') as $a) {
            $sid = $a['service_id'] ?? null;
            if ($sid === null || $sid === 0 || $sid === '') {
                return null;  // role grants all services
            }
            $ids[] = (int)$sid;
        }
        return array_values(array_unique($ids));
    }

    /** True if the broker's accessible services cover every requested service. */
    private function brokerCovers(?array $brokerIds, array $requestedIds): bool
    {
        if ($brokerIds === null) {
            return true;  // broker has all-services access
        }
        if (empty($requestedIds)) {
            return false;
        }
        return empty(array_diff($requestedIds, $brokerIds));
    }

    /** Resolve the agent behind the request's API key (set on the session by AuthCheck). */
    private function resolveAgent(): ?Agent
    {
        $apiKey = Session::getApiKey();
        return $apiKey ? Agent::whereApiKey($apiKey)->first() : null;
    }
}
