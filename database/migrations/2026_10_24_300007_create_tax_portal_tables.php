<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner's consent that the platform signs in to the tax cabinet (my.soliq.uz) with the vault key.
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('portal_access')->default(false);
            $table->string('portal_access_by')->nullable();
            $table->timestamp('portal_access_at')->nullable();
        });

        // One visit of the tax cabinet: what the portal showed (normalised) and what the analysis found.
        Schema::create('portal_checks', function (Blueprint $table) {
            $table->id();
            $table->string('status', 15)->default('running');
            $table->string('portal', 20);
            $table->string('actor_type', 15);
            $table->string('actor_name');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('findings')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Signing the cabinet's login challenge is logged next to report signatures.
        Schema::table('signature_logs', function (Blueprint $table) {
            $table->string('purpose', 15)->default('report');
        });
    }

    public function down(): void
    {
        Schema::table('signature_logs', fn (Blueprint $table) => $table->dropColumn('purpose'));
        Schema::dropIfExists('portal_checks');
        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn(['portal_access', 'portal_access_by', 'portal_access_at']));
    }
};
