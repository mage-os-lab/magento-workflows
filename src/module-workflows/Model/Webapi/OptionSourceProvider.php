<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\OptionSourceProviderInterface;
use MageOS\Workflows\Model\Option\OptionSourcePool;

/**
 * GET /V1/workflows/meta/options (F6, canvas stage 1): resolves one registered
 * option source through the pool, projecting to DTOs. An unknown source code is
 * a 404 (NoSuchEntityException) — never a silent empty list, so a typo in a
 * field's options_search surfaces.
 */
class OptionSourceProvider implements OptionSourceProviderInterface
{
    public function __construct(
        private readonly OptionSourcePool $optionSourcePool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getOptions(string $source, ?string $query = null): array
    {
        if (!$this->optionSourcePool->has($source)) {
            throw new NoSuchEntityException(
                __('Unknown workflow option source "%1"', $source)
            );
        }

        $items = [];
        foreach ($this->optionSourcePool->get($source)->fetch($query) as $option) {
            $items[] = new OptionItem(
                (string) ($option['value'] ?? ''),
                (string) ($option['label'] ?? '')
            );
        }
        return $items;
    }
}
