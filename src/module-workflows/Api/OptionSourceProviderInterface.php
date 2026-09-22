<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * GET /V1/workflows/meta/options?source=<code>&q=… (F6, canvas stage 1):
 * resolves one registered option source, optionally narrowed by a query. Backs
 * both the canvas config panels and the gallery parameter fields. ACL
 * `MageOS_Workflows::view`.
 *
 * @api
 */
interface OptionSourceProviderInterface
{
    /**
     * @param string $source registered option-source code
     * @param string|null $query optional free-text narrowing (search-typed sources)
     * @return \MageOS\Workflows\Api\Data\OptionItemInterface[]
     * @throws NoSuchEntityException when the source code is not registered
     */
    public function getOptions(string $source, ?string $query = null): array;
}
