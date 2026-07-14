<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The semantic activity ledger — the flagship of the agent control plane.
 * One row per data-plane operation, recorded where the query executes:
 * WHO (user/role/app/agent) did WHAT (verb, service, table) with WHAT RESULT
 * (status, rows, duration), all joined on the platform trace id.
 *
 * This is the record a proxy cannot produce: it sees blobs, we see tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_activity_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('trace_id', 64)->index();
            // Requestor chain: agent -> owning human -> role/app. agent_id is
            // null for plain human/API traffic — the ledger covers both.
            $table->unsignedInteger('agent_id')->nullable()->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedInteger('role_id')->nullable();
            $table->unsignedInteger('app_id')->nullable();
            // What was touched.
            $table->string('service_name', 128)->index();
            $table->string('resource', 512)->nullable();
            $table->string('table_name', 256)->nullable()->index();
            $table->string('verb', 12);
            // Outcome.
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activity_ledger');
    }
};
