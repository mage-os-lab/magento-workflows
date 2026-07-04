<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Option;

use MageOS\Workflows\Api\OptionSourceInterface;

/**
 * DI-registered pool of option sources, keyed by code (F6). Mirrors ActionPool
 * / RelationPool: one class + one di.xml array is the extension surface. Backs
 * GET /V1/workflows/meta/options.
 */
class OptionSourcePool
{
    /**
     * @param OptionSourceInterface[] $sources code => instance
     */
    public function __construct(
        private readonly array $sources = []
    ) {
        foreach ($this->sources as $code => $source) {
            if (!$source instanceof OptionSourceInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Workflow option source "%s" must implement %s', $code, OptionSourceInterface::class)
                );
            }
        }
    }

    public function has(string $code): bool
    {
        return isset($this->sources[$code]);
    }

    public function get(string $code): OptionSourceInterface
    {
        if (!isset($this->sources[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow option source "%s"', $code));
        }
        return $this->sources[$code];
    }

    /**
     * @return string[] registered source codes
     */
    public function getCodes(): array
    {
        return array_keys($this->sources);
    }
}
