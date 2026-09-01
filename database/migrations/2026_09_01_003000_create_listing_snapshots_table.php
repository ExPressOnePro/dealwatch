<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Объявления на 999.md живут недолго: продавец снимает их сразу после продажи.
 * Снимок сохраняет то, что мы видели, чтобы сделку можно было разобрать потом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);              // first_seen | price_change | archived | gone
            $table->json('payload');                   // поля объявления на момент снимка
            $table->unsignedInteger('price_mdl')->nullable();
            $table->json('image_paths')->nullable();   // локальные копии фото
            $table->string('html_path')->nullable();   // сжатая копия страницы
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index(['listing_id', 'created_at']);
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_snapshots');
    }
};
