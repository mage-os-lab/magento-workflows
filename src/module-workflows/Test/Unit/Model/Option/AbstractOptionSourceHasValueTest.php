<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Option;

use MageOS\Workflows\Model\Option\AbstractOptionSource;
use PHPUnit\Framework\TestCase;

/**
 * hasValue(): exact match over the FULL option list, uncapped — unlike fetch(),
 * which filters by substring and caps at 50 rows.
 */
class AbstractOptionSourceHasValueTest extends TestCase
{
    /**
     * 120 rows, i.e. more than fetch()'s 50-row cap.
     */
    private function source(): AbstractOptionSource
    {
        $options = [];
        for ($i = 0; $i < 120; $i++) {
            $options[] = ['value' => (string) $i, 'label' => 'Row ' . $i];
        }

        return new class ($options) extends AbstractOptionSource {
            public function __construct(private readonly array $options)
            {
            }

            public function getCode(): string
            {
                return 'test';
            }

            protected function loadOptions(): array
            {
                return $this->options;
            }
        };
    }

    public function testFindsValueBeyondTheFetchCap(): void
    {
        $source = $this->source();

        $this->assertCount(50, $source->fetch());
        $this->assertTrue($source->hasValue('0'));
        $this->assertTrue($source->hasValue('119'));
    }

    public function testMatchIsExactNotSubstring(): void
    {
        // "5" is a substring of "15"/"50"…, which fetch() would match; hasValue
        // must only accept the row whose value is exactly "5".
        $source = new class ([['value' => '15', 'label' => 'Fifteen']]) extends AbstractOptionSource {
            public function __construct(private readonly array $options)
            {
            }

            public function getCode(): string
            {
                return 'test';
            }

            protected function loadOptions(): array
            {
                return $this->options;
            }
        };

        $this->assertCount(1, $source->fetch('5'));
        $this->assertFalse($source->hasValue('5'));
        $this->assertTrue($source->hasValue('15'));
    }

    public function testMissReturnsFalse(): void
    {
        $this->assertFalse($this->source()->hasValue('999'));
        $this->assertFalse($this->source()->hasValue(''));
    }
}
