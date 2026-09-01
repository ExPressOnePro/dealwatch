<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_listings', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32)->default('999');
            $table->string('external_id');
            $table->text('note')->nullable();
            $table->timestamp('dismissed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
            $table->index('dismissed_at');
        });

        // Backfill from deals already marked dismissed.
        if (Schema::hasTable('deals') && Schema::hasTable('listings')) {
            $rows = DB::table('deals')
                ->join('listings', 'listings.id', '=', 'deals.listing_id')
                ->where('deals.user_status', 'dismissed')
                ->whereNotNull('listings.external_id')
                ->select('listings.platform', 'listings.external_id', 'deals.updated_at')
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                DB::table('ignored_listings')->insertOrIgnore([
                    'platform' => $row->platform ?: '999',
                    'external_id' => (string) $row->external_id,
                    'dismissed_at' => $row->updated_at ?? now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ignored_listings');
    }
};
