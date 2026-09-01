<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->text('rationale')->nullable()->after('liquidity');
            $table->json('basis')->nullable()->after('rationale');
            $table->string('source')->nullable()->after('basis');
        });
    }

    public function down(): void
    {
        Schema::table('market_prices', function (Blueprint $table) {
            $table->dropColumn(['rationale', 'basis', 'source']);
        });
    }
};
