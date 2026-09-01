<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('999');
            $table->string('external_id');
            $table->string('url');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->string('currency', 8)->default('MDL');
            $table->string('seller_name')->nullable();
            $table->string('seller_phone')->nullable();
            $table->string('seller_type')->nullable(); // private|shop
            $table->string('location')->nullable();
            $table->json('images')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('active'); // active|sold|hidden|stale

            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('storage_gb')->nullable();
            $table->unsignedTinyInteger('battery_health')->nullable();
            $table->string('condition')->nullable();
            $table->decimal('parse_confidence', 4, 2)->default(0);

            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
            $table->index(['status', 'price']);
            $table->index(['brand', 'model', 'storage_gb']);
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
