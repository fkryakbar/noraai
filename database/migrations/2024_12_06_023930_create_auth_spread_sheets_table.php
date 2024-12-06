<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('auth_spread_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->boolean('is_authorized')->default(false);
            $table->date('valid_until')->nullable();
            $table->integer('token_left')->default(0);
            $table->integer('total_token')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auth_spread_sheets');
    }
};
