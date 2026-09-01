<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Результаты ИИ-разбора выборки объявлений: и для показа, и как история решений. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_batch_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);              // filter | query
            $table->string('query')->nullable();       // исходный текст запроса
            $table->json('filters')->nullable();       // с чем ушли в выборку
            $table->string('status', 16)->default('running'); // running | done | failed
            $table->unsignedInteger('listing_count')->default(0);
            $table->text('summary')->nullable();
            $table->text('recommendation')->nullable();
            $table->json('items')->nullable();         // разбор по каждому объявлению
            $table->string('model_screen', 64)->nullable();
            $table->string('model_deep', 64)->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_batch_analyses');
    }
};
