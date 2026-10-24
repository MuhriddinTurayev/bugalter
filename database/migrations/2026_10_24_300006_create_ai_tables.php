<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->longText('content');
            // Tools the AI called while producing this answer: name, input and a short result.
            $table->json('tool_calls')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->boolean('is_error')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        // AI data writes are proposals; a person approves them unless auto-apply is enabled (PLAN section 6).
        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->json('payload');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->text('reason')->nullable();
            $table->string('source', 10)->default('ai');
            $table->string('status', 15)->default('pending')->index();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
