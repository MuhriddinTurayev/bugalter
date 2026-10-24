<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A tax payment the owner asked for in the platform. The desktop agent makes it in the tax cabinet (the payment is made
        // there, through the cabinet's own "new payment order"). The row says what was approved; the cabinet's own list of payment
        // orders (cabinet_payments) says what happened. Money is whole tiyin.
        Schema::create('tax_payments', function (Blueprint $table) {
            $table->id();
            $table->string('client_token', 40)->unique();   // one per opened form: a double click never pays twice
            $table->string('tax_code', 4);
            $table->string('kind', 6);                      // the cabinet's payment type: 08101 prepayment, 08102 by a report, ...
            $table->bigInteger('amount_minor');
            $table->string('purpose', 200);
            $table->string('status', 12)->default('queued')->index(); // queued, sending, paid, submitted, unknown, failed, cancelled
            $table->string('version_hash', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name');
            $table->timestamp('approved_at');
            $table->timestamp('attempt_started_at')->nullable();
            $table->string('order_number', 12)->nullable();
            $table->date('order_date')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_payment_id')->nullable()->after('tax_report_id');
        });

        // Where the taxpayer is registered: the cabinet's payment form asks for the region and district.
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('tax_region', 60)->nullable();
            $table->string('tax_district', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn(['tax_region', 'tax_district']));
        Schema::table('agent_tasks', fn (Blueprint $table) => $table->dropColumn('tax_payment_id'));
        Schema::dropIfExists('tax_payments');
    }
};
