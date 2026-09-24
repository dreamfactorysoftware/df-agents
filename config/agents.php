<?php

return [
    // Semantic activity ledger (agent_activity_ledger table). It records every
    // /api/v2 data-plane request, agent or not, so it needs a retention bound.
    'ledger' => [
        // Days of rows to keep. agents:prune-ledger is scheduled daily and
        // deletes anything older; 0 keeps rows forever (nothing is scheduled).
        'retention_days' => (int) env('DF_AGENTS_LEDGER_RETENTION_DAYS', 90),
    ],
];
