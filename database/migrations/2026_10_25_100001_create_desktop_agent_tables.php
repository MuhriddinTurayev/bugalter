<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Windows computer of this company running the desktop agent (agent/). It holds no key and no password:
        // the ERI key stays in the official E-IMZO app on that computer and the accountant types the password there.
        Schema::create('desktop_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('agent_version', 30)->nullable();
            $table->json('environment')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamps();
        });

        // One job for the agent: look at the tax cabinet, or file one approved report. Laravel decides what may be
        // done; the agent only carries it out and reports back.
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 15)->default('queued')->index();
            $table->foreignId('desktop_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_report_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('submission_attempt_id')->nullable();
            $table->unsignedBigInteger('portal_check_id')->nullable();
            $table->string('version_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->json('events')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->string('waiting_for', 30)->nullable();
            $table->string('actor_type', 15);
            $table->string('actor_name');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
        Schema::dropIfExists('desktop_agents');
    }
};
