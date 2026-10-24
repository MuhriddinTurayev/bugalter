<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned tax rules shared by every company. Seeded from docs/tax/rules.json; a value is never hardcoded.
     */
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60);
            // rate: basis points (1200 = 12%); amount: tiyin.
            $table->string('kind', 10);
            $table->bigInteger('value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('name');
            $table->text('source')->nullable();
            $table->string('status', 20)->default('needs_verification');
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['code', 'effective_from']);
        });

        Schema::create('tax_deadlines', function (Blueprint $table) {
            $table->id();
            $table->string('report_type', 20);
            $table->string('period', 10);
            $table->unsignedTinyInteger('months_after')->default(1);
            $table->unsignedTinyInteger('day');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('source')->nullable();
            $table->string('status', 20)->default('needs_verification');
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['report_type', 'period', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_deadlines');
        Schema::dropIfExists('tax_rules');
    }
};
