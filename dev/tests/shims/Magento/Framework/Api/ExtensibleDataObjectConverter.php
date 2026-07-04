<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\ExtensibleDataObjectConverter.
 *
 * EntityDataConverter only reaches this collaborator for entities that are
 * neither DataObjects nor expose __toArray(); the workflow unit tests exercise
 * DataObject/DTO entities, so this throws unless a test overrides it.
 */
class ExtensibleDataObjectConverter
{
    public function toNestedArray($dataObject, $skipCustomAttributes = [], $dataObjectType = null)
    {
        throw new \RuntimeException(
            'ExtensibleDataObjectConverter::toNestedArray() is not available in the standalone runner; '
            . 'override it in a test double.'
        );
    }
}
