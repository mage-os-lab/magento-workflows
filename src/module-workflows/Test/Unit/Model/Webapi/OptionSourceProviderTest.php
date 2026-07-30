<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\OptionSourceInterface;
use MageOS\Workflows\Model\Option\OptionSourcePool;
use MageOS\Workflows\Model\Webapi\OptionSourceProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflows/meta/options resolves one source via the pool, projects to
 * DTOs, and 404s an unknown source code.
 */
class OptionSourceProviderTest extends TestCase
{
    private function source(string $code, array $options): OptionSourceInterface
    {
        return new class ($code, $options) implements OptionSourceInterface {
            public array $lastQuery = [];

            public function __construct(private readonly string $code, private readonly array $options)
            {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function fetch(?string $query = null): array
            {
                $this->lastQuery[] = $query;
                return $this->options;
            }

            public function hasValue(string $value): bool
            {
                foreach ($this->options as $option) {
                    if (($option['value'] ?? null) === $value) {
                        return true;
                    }
                }
                return false;
            }
        };
    }

    public function testResolvesSourceToDtos(): void
    {
        $pool = new OptionSourcePool([
            'order_statuses' => $this->source('order_statuses', [
                ['value' => 'processing', 'label' => 'Processing'],
                ['value' => 'complete', 'label' => 'Complete'],
            ]),
        ]);

        $items = (new OptionSourceProvider($pool))->getOptions('order_statuses', 'proc');

        $this->assertCount(2, $items);
        $this->assertSame('processing', $items[0]->getValue());
        $this->assertSame('Processing', $items[0]->getLabel());
    }

    public function testUnknownSource404s(): void
    {
        $this->expectException(NoSuchEntityException::class);
        (new OptionSourceProvider(new OptionSourcePool([])))->getOptions('nope');
    }
}
