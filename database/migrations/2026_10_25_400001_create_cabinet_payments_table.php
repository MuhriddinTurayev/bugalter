<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The payment orders the tax cabinet lists (my.soliq.uz, "Топшириқномалар рўйхати"), kept as the cabinet shows them.
        // Money is whole tiyin. An order is identified by its number, date and tax code (numbers repeat from year to year).
        Schema::create('cabinet_payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 12);
            $table->date('order_date');
            $table->string('tax_code', 4);
            $table->string('tax', 16);
            $table->bigInteger('amount_minor');
            $table->string('status_text', 160)->default('');
            $table->boolean('is_paid')->default(false);
            $table->string('payer_bank_code', 12)->nullable();
            $table->string('payer_account', 20)->nullable();
            $table->string('receiver_account', 20)->nullable();
            $table->string('year', 4)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['order_number', 'order_date', 'tax_code']);
            $table->index('order_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_payments');
    }
};
