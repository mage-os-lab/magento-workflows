<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Framework\DataObject;

/**
 * Loads one workflow entity type through its repository and converts it to a
 * flat DataObject (custom/extension attributes lifted to top-level keys) so
 * conditions read hydrated entities and trigger snapshots identically.
 *
 * Register additional entity types by appending to the `hydrators` argument
 * of MageOS\Workflows\Model\Rule\HydrationProvider in di.xml:
 *
 * <type name="MageOS\Workflows\Model\Rule\HydrationProvider">
 *     <arguments>
 *         <argument name="hydrators" xsi:type="array">
 *             <item name="my_entity" xsi:type="object">Vendor\Module\Model\Rule\Hydrator\MyEntityHydrator</item>
 *         </argument>
 *     </arguments>
 * </type>
 *
 * Identity-map caching and the $fresh bypass are handled by the
 * HydrationProvider; implementations just load and convert.
 *
 * @api
 */
interface EntityHydratorInterface
{
    /**
     * Load the entity as a flat DataObject, or null when it does not exist
     */
    public function hydrate(int $entityId): ?DataObject;
}
