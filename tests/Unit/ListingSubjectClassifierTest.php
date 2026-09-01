<?php

namespace Tests\Unit;

use App\Services\ListingSubjectClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListingSubjectClassifierTest extends TestCase
{
    private ListingSubjectClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ListingSubjectClassifier;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function titles(): array
    {
        return [
            // Настоящий товар
            ['JBL Xtreme 3 колонка', ListingSubjectClassifier::SUBJECT_ITEM],
            ['iPhone 13 128GB, батарея 92%', ListingSubjectClassifier::SUBJECT_ITEM],
            ['Bicicletă Bergamont Gravel 29', ListingSubjectClassifier::SUBJECT_ITEM],

            // Запчасти — именно с этого начался разбор
            ['Piese JBL Charge 5-4-6 Flip 5-6 Boombox 1', ListingSubjectClassifier::SUBJECT_PARTS],
            ['Батарея для JBL eXtreme 18000 mah', ListingSubjectClassifier::SUBJECT_PARTS],
            ['iPhone 12 на запчасти', ListingSubjectClassifier::SUBJECT_PARTS],
            ['Dezmembrez iPhone 11 piese', ListingSubjectClassifier::SUBJECT_PARTS],
            ['Динамик для JBL Charge 4', ListingSubjectClassifier::SUBJECT_PARTS],

            // Аксессуары
            ['Husă pentru iPhone 13 Pro', ListingSubjectClassifier::SUBJECT_ACCESSORY],
            ['Чехол на Samsung S23 Ultra', ListingSubjectClassifier::SUBJECT_ACCESSORY],
            ['Încărcător original Apple 20W', ListingSubjectClassifier::SUBJECT_ACCESSORY],
            ['Защитное стекло iPhone 14', ListingSubjectClassifier::SUBJECT_ACCESSORY],

            // Услуги
            ['Ремонт телефонов Apple, любая сложность', ListingSubjectClassifier::SUBJECT_SERVICE],
            ['Reparatii telefoane, service autorizat', ListingSubjectClassifier::SUBJECT_SERVICE],
        ];
    }

    #[DataProvider('titles')]
    public function test_detects_what_is_being_sold(string $title, string $expected): void
    {
        $this->assertSame($expected, $this->classifier->classify($title)['subject']);
    }

    public function test_replicas_are_flagged(): void
    {
        foreach (['Boxe marca JBL. Replică. Livrare gratuit', 'Колонка JBL копия 1:1', 'Boxe Bluetooth JBL. Copie'] as $title) {
            $this->assertTrue($this->classifier->classify($title)['replica'], $title);
        }

        $this->assertFalse($this->classifier->classify('JBL Xtreme 3 оригинал, чек')['replica']);
    }

    public function test_repair_mentioned_with_negation_is_still_a_product(): void
    {
        // «Не был в ремонте» — характеристика товара, а не предложение услуги.
        $result = $this->classifier->classify(
            'iPhone 17 256GB Black 99%',
            'Stare perfectă, totul funcționează, nu a fost reparat'
        );

        $this->assertSame(ListingSubjectClassifier::SUBJECT_ITEM, $result['subject']);
    }

    public function test_accessory_as_a_bonus_does_not_change_the_subject(): void
    {
        $result = $this->classifier->classify('iPhone 13 128GB', 'Продаю телефон, в комплекте чехол и стекло');

        $this->assertSame(ListingSubjectClassifier::SUBJECT_ITEM, $result['subject']);
    }

    public function test_parts_mentioned_in_description_are_caught(): void
    {
        $result = $this->classifier->classify('JBL Charge 4', 'Продаю на запчасти, не включается');

        $this->assertSame(ListingSubjectClassifier::SUBJECT_PARTS, $result['subject']);
    }

    public function test_label_describes_the_subject(): void
    {
        $this->assertSame('запчасти', ListingSubjectClassifier::label(ListingSubjectClassifier::SUBJECT_PARTS));
        $this->assertSame('реплика', ListingSubjectClassifier::label(ListingSubjectClassifier::SUBJECT_ITEM, true));
        $this->assertSame('аксессуар · реплика', ListingSubjectClassifier::label(ListingSubjectClassifier::SUBJECT_ACCESSORY, true));
        $this->assertNull(ListingSubjectClassifier::label(ListingSubjectClassifier::SUBJECT_ITEM));
    }
}
