<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Option;

use MageOS\Workflows\Model\Option\AbstractOptionSource;
use PHPUnit\Framework\TestCase;

/**
 * The pure query-filter + cap logic shared by every option source (F6).
 */
class AbstractOptionSourceTest extends TestCase
{
    private function source(array $options): AbstractOptionSource
    {
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

    public function testEmptyQueryReturnsWholeList(): void
    {
        $opts = [
            ['value' => '1', 'label' => 'Pending'],
            ['value' => '2', 'label' => 'Processing'],
        ];
        $this->assertSame($opts, $this->source($opts)->fetch());
        $this->assertSame($opts, $this->source($opts)->fetch(''));
        $this->assertSame($opts, $this->source($opts)->fetch('   '));
    }

    public function testQueryFiltersCaseInsensitivelyOverValueAndLabel(): void
    {
        $opts = [
            ['value' => 'pending', 'label' => 'Pending'],
            ['value' => 'processing', 'label' => 'Processing'],
            ['value' => 'complete', 'label' => 'Complete'],
        ];
        $result = $this->source($opts)->fetch('PROC');
        $this->assertCount(1, $result);
        $this->assertSame('processing', $result[0]['value']);

        // Match on value even when label differs.
        $byValue = $this->source([['value' => 'sku-42', 'label' => 'Widget']])->fetch('42');
        $this->assertCount(1, $byValue);
    }

    public function testFilterCapsResults(): void
    {
        $opts = [];
        for ($i = 0; $i < 100; $i++) {
            $opts[] = ['value' => (string) $i, 'label' => 'row'];
        }
        $this->assertCount(50, AbstractOptionSource::filter($opts, null, 50));
        $this->assertCount(5, AbstractOptionSource::filter($opts, null, 5));
        $this->assertCount(100, AbstractOptionSource::filter($opts, null, 0));
    }
}
