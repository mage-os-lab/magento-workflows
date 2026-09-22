<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\DataObject;

/**
 * Shared entity/DTO => flat array conversion used by the built-in hydrators:
 * model data / DTO arrays with `custom_attributes` and `extension_attributes`
 * lifted to top-level keys, nested API objects converted to arrays, and
 * everything reduced to scalars/nulls/arrays — mirroring the shape of the
 * async-events trigger snapshot so conditions read both identically.
 */
class EntityDataConverter
{
    private const MAX_DEPTH = 6;

    public function __construct(
        private readonly ExtensibleDataObjectConverter $dataObjectConverter
    ) {
    }

    /**
     * Entity/DTO => flat array. Models expose getData(); customer-style DTOs
     * expose a public __toArray(); anything else goes through the converter
     * with its known API interface.
     */
    public function toFlatArray(object $entity, string $dtoInterface): array
    {
        if ($entity instanceof DataObject) {
            $data = $entity->getData();
        } elseif (is_callable([$entity, '__toArray'])) {
            $data = (array)$entity->__toArray();
        } else {
            $data = $this->dataObjectConverter->toNestedArray($entity, [], $dtoInterface);
        }
        $data = $this->liftApiStructures(is_array($data) ? $data : []);

        $sanitized = $this->sanitize($data, 0);

        return is_array($sanitized) ? $sanitized : [];
    }

    /**
     * Lift custom_attributes ([{attribute_code, value}, ...]) and
     * extension_attributes to top-level keys — never overwriting flat data.
     * This is what makes custom EAV attributes addressable by conditions.
     */
    private function liftApiStructures(array $data): array
    {
        $custom = $data['custom_attributes'] ?? null;
        if (is_array($custom)) {
            foreach ($custom as $attribute) {
                $code = null;
                $value = null;
                if ($attribute instanceof AttributeInterface) {
                    $code = $attribute->getAttributeCode();
                    $value = $attribute->getValue();
                } elseif (is_array($attribute)) {
                    $code = $attribute[AttributeInterface::ATTRIBUTE_CODE] ?? null;
                    $value = $attribute[AttributeInterface::VALUE] ?? null;
                }
                if (is_string($code) && $code !== '' && !array_key_exists($code, $data)) {
                    $data[$code] = $value;
                }
            }
            unset($data['custom_attributes']);
        }

        $extension = $data['extension_attributes'] ?? null;
        if (is_object($extension) && is_callable([$extension, '__toArray'])) {
            $extension = $extension->__toArray();
        }
        if (is_array($extension)) {
            foreach ($extension as $code => $value) {
                if (is_string($code) && !array_key_exists($code, $data)) {
                    $data[$code] = $value;
                }
            }
        }
        unset($data['extension_attributes']);

        return $data;
    }

    /**
     * Recursively reduce to scalars/nulls/arrays; nested models and DTOs are
     * converted, unconvertible objects dropped. Depth-limited defensively.
     */
    private function sanitize(mixed $value, int $depth): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }
        if ($value instanceof DataObject) {
            $value = $value->getData();
        } elseif (is_object($value) && is_callable([$value, '__toArray'])) {
            $value = $value->__toArray();
        }
        if (!is_array($value)) {
            return null;
        }
        $value = $this->liftApiStructures($value);
        $result = [];
        foreach ($value as $key => $item) {
            $sanitized = $this->sanitize($item, $depth + 1);
            if ($sanitized !== null || $item === null) {
                $result[$key] = $sanitized;
            }
        }
        return $result;
    }
}
