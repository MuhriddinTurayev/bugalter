<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_reports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable()->index();
            $table->string('status', 25)->default('prepared')->index();

            // Deterministic calculator output. Any change produces a new version hash and voids an approval.
            $table->bigInteger('base_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('payable_minor')->default(0);
            $table->json('lines')->nullable();
            $table->json('rules')->nullable();
            $table->json('issues')->nullable();
            $table->string('version_hash', 64);

            $table->string('prepared_actor', 15)->default('user');
            $table->string('prepared_by_name')->nullable();
            $table->timestamp('prepared_at')->nullable();

            // Approval: a person with reports.approve, or a mandate (autopilot).
            $table->string('approval_source', 15)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->unsignedBigInteger('mandate_id')->nullable();
            $table->string('approved_version_hash', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('requested_actor', 15)->nullable();

            // Cancel window, then signing and sending.
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->json('gate_results')->nullable();
            $table->unsignedBigInteger('signature_log_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('receipt_number', 80)->nullable();
            $table->json('authority_response')->nullable();
            $table->text('status_note')->nullable();
            // Set when a person cancelled: the autopilot does not ask again until a person acts on the report.
            $table->boolean('autopilot_hold')->default(false);
            $table->timestamps();

            $table->unique(['type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_reports');
    }
};
