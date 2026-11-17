<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('master-core-audit-logs.table', 'master_core_audit_events');
        if (Schema::hasTable($tableName)) {
            throw new RuntimeException("Audit Logs migration will not take ownership of existing table '{$tableName}'.");
        }

        Schema::create($tableName, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('event_name', 120);
            $table->string('category', 32);
            $table->string('outcome', 16);
            $table->string('source', 24);
            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 191)->nullable();
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('route_name', 191)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['occurred_at', 'id'], 'mc_audit_timeline_idx');
            $table->index(['category', 'occurred_at'], 'mc_audit_category_idx');
            $table->index(['event_name', 'occurred_at'], 'mc_audit_event_idx');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'mc_audit_actor_idx');
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'mc_audit_subject_idx');
            $table->index('correlation_id', 'mc_audit_correlation_idx');
        });
    }

    public function down(): void
    {
        $tableName = (string) config('master-core-audit-logs.table', 'master_core_audit_events');
        if (Schema::hasTable($tableName) && DB::table($tableName)->exists()) {
            throw new RuntimeException('Refusing to drop a populated audit table; preserve history before rollback.');
        }

        Schema::dropIfExists($tableName);
    }
};
