<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Журнал обращений к OpenAI: без учёта токенов и денег такую фичу нельзя держать включённой. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 64);                 // batch_screen, batch_deep, parse_query, …
            $table->string('tier', 16);                    // screen | deep
            $table->string('model', 64);
            $table->string('status', 16);                  // ok | cached | failed | blocked
            $table->string('input_hash', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
            $table->index(['purpose', 'created_at']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
