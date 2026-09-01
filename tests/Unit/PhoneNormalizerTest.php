<?php

namespace Tests\Unit;

use App\Services\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new PhoneNormalizer;
    }

    public function test_parses_iphone_14_pro_max_aliases(): void
    {
        $r = $this->n->parse('айфон 14 про макс 256gb батарея 87%');

        $this->assertSame('Apple', $r['brand']);
        $this->assertSame('iPhone 14 Pro Max', $r['model']);
        $this->assertSame(256, $r['storage_gb']);
        $this->assertSame(87, $r['battery_health']);
        $this->assertGreaterThan(0.5, $r['confidence']);
    }

    public function test_parses_s23_ultra(): void
    {
        $r = $this->n->parse('Samsung Galaxy S23 Ultra 256 GB');

        $this->assertSame('Samsung', $r['brand']);
        $this->assertSame('S23 Ultra', $r['model']);
        $this->assertSame(256, $r['storage_gb']);
    }

    public function test_title_wins_over_exchange_target_in_description(): void
    {
        $r = $this->n->parse(
            'Iphone 13 pink stare ideala',
            'Ca schimb as accepta un 14 pro max si 16 pro'
        );

        $this->assertSame('iPhone 13', $r['model']);
    }

    public function test_title_wins_over_trade_in_list_in_description(): void
    {
        $r = $this->n->parse(
            'Iphone 13 87% ideal',
            'рассматриваю обмен с моей доплатой на 15 про/15 про макс'
        );

        $this->assertSame('iPhone 13', $r['model']);
    }

    public function test_multi_model_catalog_title_cleared(): void
    {
        $r = $this->n->parse('Noi. Originale !!! Iphone 14Pro.15Pro.17Pro. Air. 17Pro Max. 17e.16..16+;15+;14+');

        $this->assertTrue($r['multi_model']);
        $this->assertNull($r['model']);
    }

    public function test_samsung_fold_not_stolen_by_iphone_in_description(): void
    {
        $r = $this->n->parse(
            'Samsung Galaxy Fold 5 куплен в Англии',
            'обмен на iPhone 14 Pro Max возможен'
        );

        $this->assertSame('Samsung', $r['brand']);
        $this->assertSame('Galaxy Fold 5', $r['model']);
    }

    public function test_price_list_description_does_not_override_title(): void
    {
        $r = $this->n->parse(
            'iPhone 17 Pro 256Gb',
            "iPhone 17 Pro 256Gb = 300€\niPhone 14 Pro Max = 200€"
        );

        $this->assertSame('iPhone 17 Pro', $r['model']);
    }

    public function test_pro_max_from_title(): void
    {
        $r = $this->n->parse('iPhone 13 Pro Max 128 Gb, 91%');

        $this->assertSame('iPhone 13 Pro Max', $r['model']);
        $this->assertSame(128, $r['storage_gb']);
        $this->assertSame(91, $r['battery_health']);
    }

    public function test_uncataloged_iphone_gen_not_stolen_from_description(): void
    {
        $r = $this->n->parse(
            'Apple iPhone 7 32GB - smartphone clasic',
            'обмен на 15 про возможен'
        );

        $this->assertNull($r['model']);
    }

    public function test_exchange_two_gens_in_title_cleared(): void
    {
        $r = $this->n->parse('Vând iPhone 11 / schimb pe 12');

        $this->assertTrue($r['multi_model']);
        $this->assertNull($r['model']);
    }
}
