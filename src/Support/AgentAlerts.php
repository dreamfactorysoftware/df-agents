<?php

namespace DreamFactory\Core\Agents\Support;

use DreamFactory\Core\Agents\Models\AgentAccessRequest;

/**
 * Fires agent-identity alerts through df-alerts when it is installed. df-alerts
 * is an optional dependency, so every call is guarded — no df-alerts, no-op.
 */
class AgentAlerts
{
    private const HANDLER = '\DreamFactory\Core\Alerts\Handlers\AlertEventHandler';

    public static function accessRequested(AgentAccessRequest $req): void
    {
        $name = optional($req->agent)->name ?? ('agent ' . $req->agent_id);
        $services = implode(', ', (array)$req->requested_services) ?: 'unspecified';
        $ops = implode(', ', (array)$req->requested_operations) ?: 'unspecified';
        $msg = ":raised_hand: *df-agents* — Agent `{$name}` is requesting *{$ops}* access to *{$services}*"
            . ($req->note ? "\n_\"{$req->note}\"_" : '');
        self::fire('system.agent.access_requested', $msg, [
            'agent'      => $name,
            'services'   => $services,
            'operations' => $ops,
            'request_id' => $req->id,
        ]);
    }

    public static function accessResolved(AgentAccessRequest $req): void
    {
        $name = optional($req->agent)->name ?? ('agent ' . $req->agent_id);
        $icon = $req->status === 'approved' ? ':white_check_mark:' : ':no_entry:';
        $msg = "{$icon} *df-agents* — Access request from `{$name}` was *{$req->status}*";
        self::fire('system.agent.access_' . $req->status, $msg, [
            'agent'      => $name,
            'status'     => $req->status,
            'request_id' => $req->id,
        ]);
    }

    /** Generic agent-action alert tagged with the agent name (not the key id). */
    public static function agentAction(string $agentName, string $message, array $meta = []): void
    {
        self::fire('system.agent.action', $message, ['agent' => $agentName] + $meta);
    }

    private static function fire(string $event, string $message, array $meta): void
    {
        $handler = self::HANDLER;
        if (class_exists($handler) && method_exists($handler, 'fire')) {
            try {
                $handler::fire($event, $message, $meta, $meta);
            } catch (\Throwable $e) {
                // best-effort: alerting must never break the request
            }
        }
    }
}
