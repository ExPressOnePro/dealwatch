<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('seller_key')->nullable()->after('seller_type');
            $table->unsignedSmallInteger('seller_listings_count')->default(1)->after('seller_key');
            $table->boolean('is_reseller')->default(false)->after('seller_listings_count');

            $table->index(['seller_key', 'status']);
            $table->index('is_reseller');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['seller_key', 'status']);
            $table->dropIndex(['is_reseller']);
            $table->dropColumn(['seller_key', 'seller_listings_count', 'is_reseller']);
        });
    }
};
