<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Entered by the platform owner in the panel; null means "not set". Never hardcoded.
            $table->bigInteger('price_minor')->nullable();
            $table->char('currency', 3)->default('UZS');
            $table->string('billing_period', 10)->default('month');
            // Limits such as max_users or max_documents_per_month; a missing key means unlimited.
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
