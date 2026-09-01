<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('user_status');
            $table->text('cancel_note')->nullable()->after('sale_price');
            $table->timestamp('completed_at')->nullable()->after('cancel_note');

            $table->index('is_favorite');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['is_favorite']);
            $table->dropColumn(['is_favorite', 'cancel_note', 'completed_at']);
        });
    }
};
