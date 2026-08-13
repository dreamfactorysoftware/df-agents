<?php

namespace DreamFactory\Core\Agents\Resources;

use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\System\Resources\BaseSystemResource;

/**
 * Deterministic capability router for registered agents.
 *
 *   POST /api/v2/agents/route  {"task": "...", "top": 3}
 *
 * Scores every active agent against the task description (skills keywords
 * weigh 3, name/description tokens weigh 1 — see scoreAgents) and returns
 * the winner plus its chat persona service, if one is configured. Returns a
 * routing decision only — NEVER an api_key or other credential material.
 */
class AgentRouteResource extends BaseSystemResource
{
    const RESOURCE_NAME = 'route';

    /** Routing is a computed action, not model CRUD — POST only. */
    protected function handleGET()
    {
        return false;
    }

    protected function handlePOST()
    {
        $task = trim((string)$this->getPayloadData('task', ''));
        if ($task === '') {
            throw new BadRequestException("Missing required field 'task'.");
        }
        // Optional result-list size: default 3 on missing/non-numeric, clamped to [1, 25].
        $top = $this->getPayloadData('top');
        $top = is_numeric($top) ? (int)$top : 3;
        $top = max(1, min(25, $top));

        // Load only what the scorer needs — api_key never leaves the table.
        $agents = Agent::where('is_active', true)
            ->get(['id', 'name', 'description', 'skills', 'chat_service_id']);

        $ranked = static::scoreAgents($task, $agents);
        $scores = array_map(function ($row) {
            return ['id' => $row['id'], 'name' => $row['name'], 'score' => $row['score']];
        }, array_slice($ranked, 0, $top));

        $best = $ranked[0] ?? null;
        if (!$best || $best['score'] === 0) {
            return [
                'routed_to'       => null,
                'chat_service_id' => null,
                'chat_service'    => null,
                'reason'          => 'no registered agent matches',
                'scores'          => $scores,
            ];
        }

        $winner = $agents->firstWhere('id', $best['id']);
        $chatServiceId = $winner->chat_service_id ? (int)$winner->chat_service_id : null;
        $chatServiceName = $chatServiceId ? Service::whereId($chatServiceId)->value('name') : null;

        return [
            'routed_to'       => ['id' => $best['id'], 'name' => $best['name']],
            'chat_service_id' => $chatServiceId,
            'chat_service'    => $chatServiceName,
            'reason'          => 'matched: ' . implode(', ', $best['matched']),
            'scores'          => $scores,
        ];
    }

    /**
     * Score agents against a task description. Pure and deterministic — no
     * DB, session or clock access — so behavior is fully specified here:
     *
     *   - The task, each skill keyword, and each agent's name + description
     *     are tokenized identically: lowercased, split on runs of
     *     non-alphanumerics, tokens shorter than 3 chars dropped, de-duped.
     *   - +3 per skill keyword whose tokens ALL appear in the task (so a
     *     multi-word skill like "invoice reconciliation" is a single hit).
     *   - +1 per name/description token found in the task, skipping tokens
     *     already counted as part of a matched skill (no double-counting).
     *   - Result rows are ['id', 'name', 'score', 'matched'] sorted by score
     *     desc, ties by lowest id; 'matched' lists the hits ("skill:" prefix
     *     for skill matches) for the human-readable routing reason.
     *
     * @param string   $task   free-form task description
     * @param iterable $agents rows with id, name, description, skills (JSON
     *                         array or pre-decoded); Agent models or arrays
     *
     * @return array scored rows, best first
     */
    public static function scoreAgents(string $task, iterable $agents): array
    {
        $taskTokens = static::tokenize($task);

        $rows = [];
        foreach ($agents as $agent) {
            $skills = $agent['skills'];
            if (is_string($skills)) {
                $skills = json_decode($skills, true);
            }
            $score = 0;
            $matched = [];
            $skillTokens = [];
            foreach (is_array($skills) ? $skills : [] as $keyword) {
                $kwTokens = static::tokenize((string)$keyword);
                if (!empty($kwTokens) && empty(array_diff($kwTokens, $taskTokens))) {
                    $score += 3;
                    $matched[] = 'skill:' . mb_strtolower(trim((string)$keyword));
                    $skillTokens = array_merge($skillTokens, $kwTokens);
                }
            }
            $nameDescTokens = static::tokenize(($agent['name'] ?? '') . ' ' . ($agent['description'] ?? ''));
            foreach ($nameDescTokens as $token) {
                if (in_array($token, $skillTokens, true)) {
                    continue;
                }
                if (in_array($token, $taskTokens, true)) {
                    $score += 1;
                    $matched[] = $token;
                }
            }
            $rows[] = [
                'id'      => (int)$agent['id'],
                'name'    => (string)$agent['name'],
                'score'   => $score,
                'matched' => $matched,
            ];
        }

        usort($rows, function ($a, $b) {
            return ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']);
        });

        return $rows;
    }

    /** Lowercase, split on non-alphanumerics, drop tokens < 3 chars, de-dupe. */
    public static function tokenize(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $unique = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3) {
                $unique[$token] = true;
            }
        }
        return array_keys($unique);
    }
}
