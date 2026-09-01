<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('model');
            $table->unsignedInteger('storage_gb')->nullable();
            $table->unsignedInteger('buy_min');
            $table->unsignedInteger('buy_max');
            $table->unsignedInteger('sell_low');
            $table->unsignedInteger('sell_high');
            $table->unsignedTinyInteger('liquidity')->default(5); // 1-10
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['brand', 'model', 'storage_gb']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_prices');
    }
};
