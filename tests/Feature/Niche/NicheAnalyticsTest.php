<?php

namespace Tests\Feature\Niche;

use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\SearchProfile;
use App\Models\User;
use App\Services\Niche\NicheAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NicheAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private SearchProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = SearchProfile::factory()->generic()->create(['last_scan_at' => now()]);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create(array_merge([
            'search_profile_id' => $this->profile->id,
            'first_seen_at' => now()->subDays(20),
            'published_at' => now()->subDays(20),
        ], $attributes));
    }

    public function test_fast_moving_niche_is_marked_alive(): void
    {
        // Восемь ушло за неделю-две, три ещё висят — товар оборачивается.
        foreach (range(1, 8) as $i) {
            $this->listing([
                'external_id' => 'gone-'.$i,
                'first_seen_at' => now()->subDays(14),
                'gone_at' => now()->subDays(7),
                'status' => 'gone',
                'price_mdl' => 9000 + $i * 100,
            ]);
        }

        foreach (range(1, 3) as $i) {
            $this->listing(['external_id' => 'active-'.$i, 'price_mdl' => 9500, 'first_seen_at' => now()->subDays(3), 'published_at' => now()->subDays(3)]);
        }

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);

        $this->assertSame('hot', $niche['verdict']['level']);
        $this->assertSame(8, $niche['volume']['outflow']);
        $this->assertSame(7, $niche['speed']['median_days_to_gone']);
        $this->assertSame(72.7, $niche['speed']['sell_through_percent']);
    }

    public function test_stagnant_niche_is_marked_dead(): void
    {
        foreach (range(1, 12) as $i) {
            $this->listing([
                'external_id' => 'stuck-'.$i,
                'first_seen_at' => now()->subDays(60),
                'published_at' => now()->subDays(60),
                'price_mdl' => 5000,
            ]);
        }

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);

        $this->assertSame('cold', $niche['verdict']['level']);
        $this->assertSame(0, $niche['volume']['outflow']);
        $this->assertSame(12, $niche['speed']['stale_listings']);
        $this->assertSame(100.0, $niche['speed']['stale_share_percent']);
    }

    public function test_margin_potential_uses_quartiles_and_costs(): void
    {
        foreach ([4000, 5000, 6000, 7000, 8000] as $index => $price) {
            $this->listing(['external_id' => 'price-'.$index, 'price_mdl' => $price]);
        }

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);

        $this->assertSame(5000, $niche['prices']['p25']);
        $this->assertSame(6000, $niche['prices']['median']);
        // медиана − p25 − подготовка и резерв
        $this->assertSame(6000 - 5000 - 600, $niche['prices']['margin_potential']);
    }

    public function test_repeated_listings_from_one_seller_are_detected(): void
    {
        foreach (range(1, 3) as $i) {
            $this->listing([
                'external_id' => 'repeat-'.$i,
                'title' => 'Bergamont Grandurance Gravel 29 колёса',
                'seller_key' => '999:owner:42',
                'price_mdl' => 9000,
            ]);
        }
        $this->listing(['external_id' => 'other', 'title' => 'Scott Scale 970', 'seller_key' => '999:owner:77']);

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);

        $this->assertSame(1, $niche['repeats']['groups']);
        $this->assertSame(3, $niche['repeats']['items'][0]['times']);
        $this->assertSame(75.0, $niche['repeats']['share_percent']);
    }

    public function test_price_cuts_are_measured_from_snapshots(): void
    {
        $listing = $this->listing(['external_id' => 'cut-1', 'price_mdl' => 8000]);

        ListingSnapshot::create([
            'listing_id' => $listing->id,
            'reason' => ListingSnapshot::REASON_FIRST_SEEN,
            'payload' => [],
            'price_mdl' => 10000,
        ]);
        ListingSnapshot::create([
            'listing_id' => $listing->id,
            'reason' => ListingSnapshot::REASON_PRICE_CHANGE,
            'payload' => [],
            'price_mdl' => 8000,
        ]);

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);

        $this->assertSame(1, $niche['price_moves']['tracked_listings']);
        $this->assertSame(1, $niche['price_moves']['with_price_cut']);
        $this->assertSame(20.0, $niche['price_moves']['avg_cut_percent']);
    }

    public function test_top_sellers_show_how_each_account_trades(): void
    {
        foreach (range(1, 4) as $i) {
            $this->listing([
                'external_id' => 'flow-'.$i,
                'seller_key' => '999:owner:100',
                'is_reseller' => true,
                'gone_at' => $i <= 2 ? now()->subDays(5) : null,
                'first_seen_at' => now()->subDays(15),
                'price_mdl' => 7000,
            ]);
        }

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);
        $top = $niche['top_sellers'][0];

        $this->assertSame('999:owner:100', $top['seller_key']);
        $this->assertSame(4, $top['listings']);
        $this->assertSame(2, $top['gone']);
        $this->assertTrue($top['is_reseller']);
        $this->assertSame(10, $top['median_days_to_gone']);
    }

    public function test_young_niche_is_not_called_dead(): void
    {
        // Собрали вчера десяток объявлений: продаж ещё быть не могло.
        foreach (range(1, 10) as $i) {
            $this->listing([
                'external_id' => 'young-'.$i,
                'first_seen_at' => now()->subDay(),
                'published_at' => now()->subDay(),
                'price_mdl' => 6000,
            ]);
        }

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);

        $this->assertSame('unknown', $niche['verdict']['level']);
        $this->assertSame('Собираем данные', $niche['verdict']['label']);
        $this->assertSame(1, $niche['observation_days']);
    }

    public function test_niche_without_scan_says_so(): void
    {
        $profile = SearchProfile::factory()->generic()->create(['name' => 'Без переписи', 'last_scan_at' => null]);

        $niche = app(NicheAnalytics::class)->forProfile($profile, 30);

        $this->assertSame('unknown', $niche['verdict']['level']);
        $this->assertStringContainsString('Перепись каталога ещё не запускалась', $niche['verdict']['note']);
    }

    public function test_source_diagnostics_report_non_items(): void
    {
        foreach ([3800, 4000, 4500, 5000] as $index => $price) {
            $this->listing(['external_id' => 'speaker-'.$index, 'title' => 'JBL Xtreme '.$index, 'price_mdl' => $price]);
        }
        // Запчасти и реплики отбрасываются из рынка — об этом надо сказать вслух.
        $this->listing([
            'external_id' => 'battery',
            'title' => 'Батарея для JBL eXtreme 18000 mah',
            'price_mdl' => 750,
            'subject' => 'parts',
        ]);
        $this->listing(['external_id' => 'copy', 'title' => 'Boxe JBL. Copie', 'price_mdl' => 399, 'is_replica' => true]);

        $niche = app(NicheAnalytics::class)->forProfile($this->profile, 30);
        $hints = collect($niche['hints']);

        $this->assertSame(2, $niche['volume']['non_items']);
        $this->assertSame(4, $niche['volume']['active']);
        $this->assertTrue($hints->contains(fn ($h) => $h['type'] === 'non_items'));
        $this->assertStringContainsString('стоп-список', $hints->firstWhere('type', 'non_items')['text']);
    }

    public function test_wide_price_spread_is_flagged(): void
    {
        foreach ([1000, 1200, 8000, 12000] as $index => $price) {
            $this->listing(['external_id' => 'mixed-'.$index, 'title' => 'Разное '.$index, 'price_mdl' => $price]);
        }

        $hints = collect(app(NicheAnalytics::class)->forProfile($this->profile, 30)['hints']);

        $this->assertTrue($hints->contains(fn ($h) => $h['type'] === 'wide_spread'));
    }

    public function test_page_renders_selected_niche(): void
    {
        $this->listing(['external_id' => 'one', 'price_mdl' => 6000]);

        $this->actingAs(User::factory()->create())
            ->get('/niches?profile='.$this->profile->id.'&days=30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('niches/index')
                ->where('selected_id', $this->profile->id)
                ->where('days', 30)
                ->has('niche.verdict')
                ->has('niche.weekly')
            );
    }
}
