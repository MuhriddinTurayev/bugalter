<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_form', 30)->nullable();
            $table->string('stir', 9)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account', 30)->nullable();
            $table->string('mfo', 5)->nullable();
            $table->string('director_name')->nullable();
            $table->string('chief_accountant_name')->nullable();
            $table->boolean('vat_payer')->default(false);
            $table->string('vat_registration_code', 20)->nullable();
            // Entered by the user; used only as the default for new document lines.
            $table->unsignedSmallInteger('default_vat_rate_bp')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
