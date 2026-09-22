<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Model\Option;

use Magento\Store\Api\WebsiteRepositoryInterface;
use MageOS\Workflows\Model\Option\AbstractOptionSource;

/**
 * Bounded option source: website id => name (F6). Websites are small and fully
 * enumerable, so this is referenced with min_chars: 0 or inlined by the
 * product.assign_websites action and the website_ids product condition (PRD-C3
 * / PRD-A1). Value is the website id, mirroring category_ids' id semantics.
 */
class WebsiteOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    public function getCode(): string
    {
        return 'websites';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $options = [];
        foreach ($this->websiteRepository->getList() as $website) {
            $options[] = [
                'value' => (string) $website->getId(),
                'label' => (string) $website->getName(),
            ];
        }
        return $options;
    }
}
