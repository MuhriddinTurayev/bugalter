<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // general: VAT + profit tax; turnover: turnover tax instead of both.
            $table->string('tax_regime', 20)->default('general');
            $table->boolean('is_budget_org')->default(false);

            // Autopilot (PLAN section 5): 0 off, 1 prepare, 2 ask, 3 auto under a mandate.
            $table->unsignedTinyInteger('autopilot_level')->default(0);
            $table->unsignedSmallInteger('cancel_window_hours')->default(24);
            $table->timestamp('autopilot_paused_at')->nullable();
            $table->string('autopilot_paused_by')->nullable();
            // vault: server-side signing with a key from the vault; device: the owner signs with E-IMZO.
            $table->string('signer_mode', 10)->default('vault');

            // AI accountant: an optional company override of the platform's provider, model and key.
            $table->string('ai_provider', 20)->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->text('ai_api_key')->nullable();
            $table->json('ai_auto_apply')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'tax_regime', 'is_budget_org', 'autopilot_level', 'cancel_window_hours', 'autopilot_paused_at',
                'autopilot_paused_by', 'signer_mode', 'ai_provider', 'ai_model', 'ai_api_key', 'ai_auto_apply',
            ]);
        });
    }
};
