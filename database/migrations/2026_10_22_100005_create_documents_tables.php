<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['type', 'year']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('direction', 10)->default('out');
            $table->string('number', 40)->unique();
            $table->foreignId('contractor_id')->constrained()->restrictOnDelete();
            $table->date('issue_date')->index();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->char('currency', 3)->default('UZS');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_minor');
            $table->unsignedSmallInteger('vat_rate_bp')->default(0);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('vat_minor');
            $table->bigInteger('total_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_sequences');
    }
};
