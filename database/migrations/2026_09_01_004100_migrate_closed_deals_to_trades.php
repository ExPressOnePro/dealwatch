<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Закрытые сделки из «Избранного» переезжают в журнал — там теперь вся отчётность. */
return new class extends Migration
{
    public function up(): void
    {
        $ownerId = DB::table('users')->where('is_admin', true)->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if (! $ownerId) {
            return;
        }

        $rows = DB::table('deals')
            ->join('listings', 'listings.id', '=', 'deals.listing_id')
            ->whereIn('deals.user_status', ['completed', 'cancelled', 'bought', 'sold'])
            ->select([
                'deals.id as deal_id',
                'deals.listing_id',
                'deals.user_status',
                'deals.purchase_price',
                'deals.sale_price',
                'deals.completed_at',
                'deals.cancel_note',
                'deals.created_at',
                'listings.title',
                'listings.brand',
                'listings.model',
                'listings.storage_gb',
            ])
            ->get();

        foreach ($rows as $row) {
            $status = match ($row->user_status) {
                'completed', 'sold' => 'sold',
                'cancelled' => 'cancelled',
                default => 'bought',
            };

            DB::table('trades')->insert([
                'user_id' => $ownerId,
                'listing_id' => $row->listing_id,
                'deal_id' => $row->deal_id,
                'title' => $row->title,
                'brand' => $row->brand,
                'model' => $row->model,
                'storage_gb' => $row->storage_gb,
                'status' => $status,
                'purchase_price' => $row->purchase_price,
                'purchase_date' => $row->created_at ? substr((string) $row->created_at, 0, 10) : null,
                'sale_price' => $row->sale_price,
                'sale_date' => $row->completed_at ? substr((string) $row->completed_at, 0, 10) : null,
                'notes' => $row->cancel_note,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('trades')->whereNotNull('deal_id')->delete();
    }
};
