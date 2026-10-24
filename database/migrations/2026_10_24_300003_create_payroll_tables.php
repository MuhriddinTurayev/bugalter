<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period')->unique(); // first day of the month
            $table->string('status', 15)->default('draft');
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('pit_minor')->default(0);
            $table->bigInteger('inps_minor')->default(0);
            $table->bigInteger('social_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->json('rules')->nullable();
            $table->string('prepared_actor', 15)->default('user');
            $table->string('prepared_by_name')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_name');
            $table->string('position')->nullable();
            $table->bigInteger('gross_minor');
            $table->bigInteger('pit_minor');
            $table->bigInteger('inps_minor');
            $table->bigInteger('social_minor');
            $table->bigInteger('net_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
