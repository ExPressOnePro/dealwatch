<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_price_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('market_price')->nullable();
            $table->decimal('discount_percent', 6, 2)->nullable();
            $table->integer('potential_profit')->nullable();
            $table->unsignedTinyInteger('deal_score')->default(0);
            $table->string('verdict')->default('ignore'); // buy|check|ignore
            $table->string('freshness')->default('new'); // fresh|new|old|stale
            $table->unsignedTinyInteger('liquidity')->nullable();
            $table->boolean('notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->string('user_status')->default('new'); // new|opened|called|bought|sold|dismissed
            $table->unsignedInteger('purchase_price')->nullable();
            $table->unsignedInteger('sale_price')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->timestamps();

            $table->unique('listing_id');
            $table->index(['deal_score', 'verdict']);
            $table->index('user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
