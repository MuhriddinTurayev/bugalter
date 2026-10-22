<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('pinfl', 14)->nullable();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('phone', 30)->nullable();
            $table->date('hired_on')->nullable();
            $table->string('status', 15)->default('active');
            $table->bigInteger('salary_minor')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
