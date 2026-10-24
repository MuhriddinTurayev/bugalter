<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An entrepreneur's cabinet at my.soliq.uz identifies the taxpayer by the 14-digit PINFL, not by the 9-digit STIR.
        // The desktop agent compares what the cabinet shows with the STIR or this PINFL and stops on any difference.
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('pinfl', 14)->nullable()->after('stir');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn('pinfl'));
    }
};
