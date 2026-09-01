<?php

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FavoriteFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->deal = Deal::factory()->create(['listing_id' => Listing::factory()->create()->id]);
    }

    public function test_deal_can_be_starred(): void
    {
        $this->actingAs($this->user)->post("/deals/{$this->deal->id}/favorite")->assertRedirect();

        $this->assertTrue($this->deal->fresh()->is_favorite);
    }

    public function test_completion_requires_a_starred_deal(): void
    {
        $this->actingAs($this->user)
            ->post("/favorites/{$this->deal->id}/complete", ['purchase_price' => 8000, 'sale_price' => 11000])
            ->assertSessionHas('error');

        $this->assertSame(Deal::STATUS_NEW, $this->deal->fresh()->user_status);
    }

    public function test_completed_deal_carries_net_profit(): void
    {
        $this->deal->update(['is_favorite' => true]);

        $this->actingAs($this->user)
            ->post("/favorites/{$this->deal->id}/complete", ['purchase_price' => 8000, 'sale_price' => 11000])
            ->assertRedirect(route('favorites.index', ['tab' => 'completed']));

        $deal = $this->deal->fresh();
        $this->assertSame(Deal::STATUS_COMPLETED, $deal->user_status);
        $this->assertNotNull($deal->completed_at);
        $this->assertSame(3000, $deal->netProfit());

        $this->actingAs($this->user)
            ->get(route('favorites.index', ['tab' => 'completed']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('favorites/index')
                ->has('items', 1)
                ->where('stats.net_profit', 3000)
                ->where('stats.turnover', 11000)
            );
    }

    public function test_cancelled_deal_keeps_the_note(): void
    {
        $this->deal->update(['is_favorite' => true]);

        $this->actingAs($this->user)
            ->post("/favorites/{$this->deal->id}/cancel", ['cancel_note' => 'Продавец не выходит на связь'])
            ->assertRedirect(route('favorites.index', ['tab' => 'cancelled']));

        $deal = $this->deal->fresh();
        $this->assertSame(Deal::STATUS_CANCELLED, $deal->user_status);
        $this->assertSame('Продавец не выходит на связь', $deal->cancel_note);
    }

    public function test_unstarring_does_not_reopen_a_closed_deal(): void
    {
        $this->deal->update(['is_favorite' => true, 'user_status' => Deal::STATUS_COMPLETED]);

        $this->actingAs($this->user)->delete("/deals/{$this->deal->id}/favorite");

        $deal = $this->deal->fresh();
        $this->assertFalse($deal->is_favorite);
        $this->assertSame(Deal::STATUS_COMPLETED, $deal->user_status);
    }
}
