<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

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
 * ExecutionContextInterface::getDedupeKey($stepKey) before performing the
 * side effect (at-least-once delivery).
 *
 * @api
 */
interface ActionInterface
{
    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface;
}
