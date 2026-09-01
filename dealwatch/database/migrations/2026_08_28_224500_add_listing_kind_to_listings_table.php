<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('listing_kind', 32)->default('sell')->after('seller_type');
            $table->index(['listing_kind', 'status']);
            $table->index(['seller_type', 'listing_kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['seller_type', 'listing_kind', 'status']);
            $table->dropIndex(['listing_kind', 'status']);
            $table->dropColumn('listing_kind');
        });
    }
};
