<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * A workflow action. Register implementations into
 * MageOS\Workflows\Model\Action\ActionPool via di.xml:
 *
 * <type name="MageOS\Workflows\Model\Action\ActionPool">
 *     <arguments>
 *         <argument name="actions" xsi:type="array">
 *             <item name="order.add_comment" xsi:type="object">Vendor\Module\Action\AddComment</item>
 *         </argument>
 *     </arguments>
 * </type>
 *
 * Config values are interpolated ({{ trigger.* }}, {{ steps.* }}, {{ workflow.* }},
 * {{ secrets.* }}) BEFORE execute() is called. Interpolation supplies values,
 * never structure: config keys and the action code itself are static.
 *
 * Actions must be idempotent where cheap; otherwise consult
 * ExecutionContext::getDedupeKey($stepKey) before performing the side effect
 * (at-least-once delivery).
 */
interface ActionInterface
{
    public function execute(ExecutionContext $ctx, array $config): ActionResult;
}
