<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The company owner is invited by link; only the hash of the token is stored.
            $table->string('invitation_token_hash', 64)->nullable()->index()->after('remember_token');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token_hash');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invitation_token_hash', 'invitation_expires_at']);
        });
    }
};
