<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Курсы валют храним в базе: если источник недоступен, лучше взять последний
 * реально полученный курс, чем зашитую в .env цифру, которая устаревает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8);
            $table->decimal('rate', 12, 4);          // сколько MDL за единицу валюты
            $table->string('source', 32);            // bnm | fallback
            $table->timestamp('rate_date')->nullable();
            $table->timestamps();

            $table->unique(['code', 'rate_date']);
            $table->index(['code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
