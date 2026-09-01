<?php

namespace App\Services;

use App\Models\Listing;
use App\Services\Collectors\NineNinetyNineCollector;

class ListingDetailEnricher
{
    public function __construct(
        private readonly NineNinetyNineCollector $collector,
        private readonly ListingKindClassifier $kinds,
        private readonly PhoneNormalizer $normalizer,
    ) {}

    /**
     * Pull authoritative description, region, site model from 999.md.
     */
    public function enrich(Listing $listing): bool
    {
        if ($listing->platform !== '999' || ! $listing->external_id) {
            return false;
        }

        $detail = $this->collector->fetchDetail($listing->external_id);
        if (! $detail) {
            return false;
        }

        $updates = [];

        if (! empty($detail['description']) && trim((string) $detail['description']) !== trim((string) $listing->description)) {
            $updates['description'] = trim((string) $detail['description']);
        }

        if (! empty($detail['location']) && $detail['location'] !== $listing->location) {
            $updates['location'] = $detail['location'];
        }

        if (! empty($detail['url'])) {
            $updates['url'] = $detail['url'];
        }

        $siteModel = isset($detail['site_model']) ? trim((string) $detail['site_model']) : '';
        if ($siteModel !== '' && $siteModel !== (string) ($listing->site_model ?? '')) {
            $updates['site_model'] = $siteModel;
        }

        $modelInputsChanged = isset($updates['site_model']) || isset($updates['description']);
        if ($modelInputsChanged || ($siteModel === '' && ! $listing->model)) {
            $parsed = $this->normalizer->parse(
                (string) $listing->title,
                $updates['description'] ?? $listing->description,
                $updates['site_model'] ?? $listing->site_model
            );
            $updates['brand'] = $parsed['brand'];
            $updates['model'] = $parsed['model'];
            $updates['model_source'] = $parsed['model_source'];
            $updates['parse_confidence'] = $parsed['confidence'];
            if ($parsed['storage_gb'] && ! $listing->storage_gb) {
                $updates['storage_gb'] = $parsed['storage_gb'];
            }
        }

        if (empty($updates)) {
            return false;
        }

        $listing->forceFill($updates)->save();
        $this->kinds->apply($listing);

        return true;
    }
}
