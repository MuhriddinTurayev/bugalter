<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every approval of a report version, by a person or a mandate. Kept when the approval is voided, so the
        // history shows who approved which figures and why the approval ended.
        Schema::create('report_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_report_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch')->nullable()->index();
            $table->string('source', 15);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->unsignedBigInteger('mandate_id')->nullable();
            $table->string('version_hash', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('payable_minor');
            $table->string('company_tin', 14)->nullable();
            $table->boolean('declaration')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('approved_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason')->nullable();
            $table->timestamps();

            $table->index(['tax_report_id', 'invalidated_at']);
        });

        // One delivery of a signed report to the authority. The row is written before anything leaves the
        // application, so a crash mid-delivery is visible as "sending" and is never retried blindly.
        Schema::create('submission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_report_id')->constrained()->cascadeOnDelete();
            // Stable for one approved version: a retry after "not received" reuses it, so the authority can
            // recognise a duplicate. A new key only after a definite rejection or a new version.
            $table->string('idempotency_key', 64)->index();
            $table->string('version_hash', 64);
            $table->string('document_hash', 64)->nullable();
            $table->string('gateway', 20);
            $table->string('status', 15)->default('sending');
            $table->string('receipt_number', 80)->nullable();
            $table->json('errors')->nullable();
            $table->json('response')->nullable();
            $table->unsignedBigInteger('signature_log_id')->nullable();
            $table->string('actor_type', 15);
            $table->string('actor_name');
            $table->string('approval_ref')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['tax_report_id', 'status']);
        });

        // "Prepare reports automatically": one background run with its steps and per-report results.
        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 15)->default('queued')->index();
            $table->date('period_month');
            $table->json('steps')->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->string('actor_type', 15);
            $table->string('actor_name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('submission_attempts');
        Schema::dropIfExists('report_approvals');
    }
};
