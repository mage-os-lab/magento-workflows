<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\ObjectManagerInterface;
use Magento\Rule\Model\Condition\Combine;

/**
 * DI-registered map entity_type => root combine class — the generalization
 * of salesrule/catalogrule's hardcoded getConditionsInstance()
 * (docs/06-conditions.md). Extensions register additional entity types by
 * appending to the `combines` argument in di.xml.
 */
class ConditionCombinePool
{
    // All four built-in roots are contributed via di.xml by their domain packs
    // (sales_order/quote: mage-os/workflows-sales, S1; customer:
    // mage-os/workflows-customer, S2; catalog_product: mage-os/workflows-catalog,
    // S3) — nothing is defaulted here because the classes live outside this
    // package. The const remains as the extension seam contract.
    private const DEFAULT_COMBINES = [
    ];

    /**
     * @var array<string, string> entity_type => combine class name
     */
    private readonly array $combines;

    /**
     * The ObjectManager is used directly (instead of per-class factories)
     * because combine class names are dynamic data from the DI map — this
     * mirrors how the core rule module itself instantiates condition classes
     * by name (\Magento\Rule\Model\ConditionFactory).
     *
     * @param array<string, string> $combines entity_type => combine class name (merged over defaults)
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        array $combines = []
    ) {
        $this->combines = array_merge(self::DEFAULT_COMBINES, $combines);
    }

    /**
     * Create a fresh root combine for the entity type. Always a new instance:
     * combines are stateful (children, aggregator) and never shareable.
     *
     * @throws \InvalidArgumentException on unknown entity type or invalid class
     */
    public function getCombine(string $entityType): Combine
    {
        $class = $this->combines[$entityType] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException(
                sprintf('No workflow condition combine registered for entity type "%s"', $entityType)
            );
        }
        $combine = $this->objectManager->create($class);
        if (!$combine instanceof Combine) {
            throw new \InvalidArgumentException(
                sprintf('Workflow condition combine "%s" for entity type "%s" must extend %s', $class, $entityType, Combine::class)
            );
        }
        return $combine;
    }
}
