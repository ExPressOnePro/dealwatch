<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал сделок пользователя: что купил, за сколько, во что вложился,
 * кому и за сколько продал. Живёт отдельно от объявления — объявление
 * снимут, а сделка и отчётность останутся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('listing_snapshots')->nullOnDelete();

            // Копия описания товара: объявление может исчезнуть, отчётность — нет.
            $table->string('title');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('storage_gb')->nullable();

            $table->string('status', 16)->default('bought'); // planned|bought|listed|sold|cancelled
            $table->unsignedInteger('purchase_price')->nullable();
            $table->date('purchase_date')->nullable();
            $table->unsignedInteger('sale_price')->nullable();
            $table->date('sale_date')->nullable();
            $table->string('sale_channel')->nullable();      // 999 / Facebook / знакомые …
            $table->string('buyer_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'sale_date']);
            $table->index(['brand', 'model']);
        });

        Schema::create('trade_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->integer('amount');   // MDL, может быть отрицательным (возврат)
            $table->timestamps();

            $table->index('trade_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_expenses');
        Schema::dropIfExists('trades');
    }
};
