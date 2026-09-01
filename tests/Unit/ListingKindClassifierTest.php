<?php

namespace Tests\Unit;

use App\Services\ListingKindClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListingKindClassifierTest extends TestCase
{
    private ListingKindClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ListingKindClassifier;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function titles(): array
    {
        return [
            ['Продам iPhone 13 128GB', ListingKindClassifier::KIND_SELL],
            ['Vând iPhone 13 Pro, stare ideală', ListingKindClassifier::KIND_SELL],
            ['iPhone 13 128GB', ListingKindClassifier::KIND_SELL],
            ['Куплю iPhone в любом состоянии', ListingKindClassifier::KIND_WANT_BUY],
            ['Скупка телефонов дорого', ListingKindClassifier::KIND_WANT_BUY],
            ['Cumpăr telefoane Apple', ListingKindClassifier::KIND_WANT_BUY],
            ['Caut iPhone 12 sau 13', ListingKindClassifier::KIND_WANT_BUY],
            // «куплен» — это про историю телефона, а не про намерение купить
            ['iPhone 13 куплен в 2022, продаю срочно', ListingKindClassifier::KIND_SELL],
            // магазинные форматы всегда предложение, даже со словом «cumpărăm»
            ['iPhone 15 Pro credit 0% avans 0, garanție 24 luni', ListingKindClassifier::KIND_SELL],
            ['Trade-in: меняем ваш старый телефон', ListingKindClassifier::KIND_SELL],
        ];
    }

    #[DataProvider('titles')]
    public function test_classifies_title_intent(string $title, string $expected): void
    {
        $this->assertSame($expected, $this->classifier->classify($title));
    }

    public function test_empty_title_defaults_to_sell(): void
    {
        $this->assertSame(ListingKindClassifier::KIND_SELL, $this->classifier->classify(''));
    }

    public function test_description_does_not_override_a_sell_title(): void
    {
        $kind = $this->classifier->classify(
            'iPhone 13 128GB',
            'Cumpărăm telefonul vechi în contul celui nou'
        );

        $this->assertSame(ListingKindClassifier::KIND_SELL, $kind);
    }
}
