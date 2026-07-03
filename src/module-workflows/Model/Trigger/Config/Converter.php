<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Trigger\Config;

use Magento\Framework\Config\ConverterInterface;

/**
 * Converts the merged workflow_triggers.xml DOM into the runtime array:
 *
 * [
 *   'sales.order.created' => [
 *       'event'    => 'sales.order.created',
 *       'entity'   => 'sales_order',
 *       'label'    => 'Order Created',
 *       'group'    => 'Sales',          // null when omitted
 *       'resolver' => 'Vendor\Module\Model\Resolver\OrderResolver', // null when omitted
 *   ],
 *   ...
 * ]
 */
class Converter implements ConverterInterface
{
    /**
     * @param \DOMDocument $source
     * @return array<string, array<string, string|null>>
     */
    public function convert($source): array
    {
        $triggers = [];
        foreach ($source->getElementsByTagName('trigger') as $triggerNode) {
            /** @var \DOMElement $triggerNode */
            $event = $triggerNode->getAttribute('event');
            if ($event === '') {
                continue;
            }
            $triggers[$event] = [
                'event' => $event,
                'entity' => $triggerNode->getAttribute('entity'),
                'label' => $triggerNode->getAttribute('label'),
                'group' => $this->optionalAttribute($triggerNode, 'group'),
                'resolver' => $this->optionalAttribute($triggerNode, 'resolver'),
            ];
        }

        return $triggers;
    }

    private function optionalAttribute(\DOMElement $node, string $name): ?string
    {
        $value = $node->getAttribute($name);

        return $value !== '' ? $value : null;
    }
}
