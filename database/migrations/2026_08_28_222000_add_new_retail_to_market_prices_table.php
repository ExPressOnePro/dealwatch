<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->unsignedInteger('new_price_mdl')->nullable()->after('sell_high');
            $table->unsignedTinyInteger('new_warranty_months')->nullable()->after('new_price_mdl');
            $table->string('new_shop')->nullable()->after('new_warranty_months');
            $table->string('new_note')->nullable()->after('new_shop');
        });
    }

    public function down(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->dropColumn(['new_price_mdl', 'new_warranty_months', 'new_shop', 'new_note']);
        });
    }
};
