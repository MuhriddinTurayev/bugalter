<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A written standing authorisation from the owner (PLAN section 5, "Mandate").
        Schema::create('mandates', function (Blueprint $table) {
            $table->id();
            $table->json('report_types');
            $table->date('period_from');
            $table->date('period_to');
            $table->bigInteger('amount_limit_minor');
            $table->unsignedSmallInteger('change_limit_percent')->nullable();
            $table->date('expires_at');
            $table->string('status', 15)->default('active');
            $table->string('signed_name');
            $table->timestamp('signed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamps();
        });

        // ERI/E-imzo keys. Envelope encryption: the key and its password are encrypted with a per-key data key,
        // and the data key with the master key held outside the database (SIGNING_MASTER_KEY).
        Schema::create('signing_keys', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('owner_name');
            $table->string('owner_tin', 14)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to');
            $table->string('status', 15)->default('active');
            $table->longText('encrypted_key');
            $table->text('encrypted_password');
            $table->text('encrypted_data_key');
            $table->string('fingerprint', 64);
            $table->json('expiry_warnings_sent')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->timestamps();
        });

        // Every signing. Never stores keys, passwords or document contents.
        Schema::create('signature_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signing_key_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_report_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signer', 15);
            $table->string('document_hash', 64);
            $table->string('signature_hash', 64);
            $table->string('actor_type', 15);
            $table->string('actor_name');
            $table->string('approval_ref')->nullable();
            $table->unsignedBigInteger('mandate_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_logs');
        Schema::dropIfExists('signing_keys');
        Schema::dropIfExists('mandates');
    }
};
