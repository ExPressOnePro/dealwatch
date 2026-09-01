<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разбор конкретного объявления ИИ — по тексту и, если попросили, по фотографиям.
 * Хранится рядом с объявлением: выводы должны быть видны в карточке, а не
 * теряться в истории пачечных разборов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_ai_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);                 // text | vision
            $table->string('status', 16)->default('running'); // running | done | failed
            $table->string('model', 64)->nullable();
            $table->string('verdict', 16)->nullable();  // take | check | skip
            $table->unsignedTinyInteger('condition_score')->nullable();
            $table->unsignedInteger('target_price_mdl')->nullable();
            $table->text('summary')->nullable();
            $table->json('payload')->nullable();        // дефекты, вопросы, несоответствия
            $table->unsignedTinyInteger('images_analyzed')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_ai_reports');
    }
};
