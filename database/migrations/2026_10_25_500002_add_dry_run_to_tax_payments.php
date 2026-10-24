<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A check without paying: the agent fills the cabinet's payment form and reads its confirmation window, then cancels the window.
        // The server never lets such a payment reach the confirm button.
        Schema::table('tax_payments', function (Blueprint $table) {
            $table->boolean('dry_run')->default(false)->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('tax_payments', fn (Blueprint $table) => $table->dropColumn('dry_run'));
    }
};
