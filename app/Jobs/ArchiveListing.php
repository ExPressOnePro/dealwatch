<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Services\Archive\ListingArchivist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Скачивание фото и копии страницы — сеть, поэтому фоном. */
class ArchiveListing implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public int $listingId,
    ) {}

    public function handle(ListingArchivist $archivist): void
    {
        $listing = Listing::find($this->listingId);

        if (! $listing) {
            return;
        }

        $archivist->archive($listing);
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('Archive job failed for listing '.$this->listingId.': '.($e?->getMessage() ?? 'unknown'));
    }
}
