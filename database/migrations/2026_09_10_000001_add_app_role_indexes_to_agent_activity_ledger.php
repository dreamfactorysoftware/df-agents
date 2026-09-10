<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-app and per-role lookups on the activity ledger ("when did this app or
 * role last make a request", "requests in the last N days"). The create
 * migration only indexed agent/user/service/table, so those queries scanned a
 * table that takes one row per data-plane request.
 *
 * up() skips a column set that is already indexed under any name (e.g. added
 * by hand on a busy install); down() only drops the names created here.
 */
return new class extends Migration {
    private const TABLE = 'agent_activity_ledger';

    private const INDEXES = [
        'agent_activity_ledger_app_id_occurred_at_index'  => ['app_id', 'occurred_at'],
        'agent_activity_ledger_role_id_occurred_at_index' => ['role_id', 'occurred_at'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if (Schema::hasIndex(self::TABLE, $columns)) {
                continue;
            }
            Schema::table(self::TABLE, function (Blueprint $table) use ($name, $columns) {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if (!Schema::hasIndex(self::TABLE, $name)) {
                continue;
            }
            Schema::table(self::TABLE, function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }
    }
};
