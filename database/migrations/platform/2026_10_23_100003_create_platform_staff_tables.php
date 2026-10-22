<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role', 20)->default('support');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_timestamp')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('invitation_token_hash', 64)->nullable()->index();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Append-only: rows are never updated or deleted by the application.
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('platform_staff')->nullOnDelete();
            $table->string('staff_name')->nullable();
            $table->string('action', 80)->index();
            $table->string('tenant_id', 63)->nullable()->index();
            $table->text('reason')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('platform_staff')->cascadeOnDelete();
            $table->string('tenant_id', 63)->index();
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ip', 45)->nullable();
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('support_sessions');
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('platform_staff');
    }
};
