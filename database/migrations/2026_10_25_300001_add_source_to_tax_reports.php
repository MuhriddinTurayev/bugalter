<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "books": figures calculated by Bugalter from the company's own records. "cabinet": figures the tax authority's own
        // reporting system calculated (from e-invoices and online cash registers) and the agent read from the real form.
        Schema::table('tax_reports', function (Blueprint $table) {
            $table->string('source', 16)->default('books')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('tax_reports', fn (Blueprint $table) => $table->dropColumn('source'));
    }
};
