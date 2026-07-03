<?php
declare(strict_types=1);

namespace Magento\Framework\Exception;

use Magento\Framework\Phrase;

/**
 * Standalone-runner shim for Magento\Framework\Exception\NoSuchEntityException.
 */
class NoSuchEntityException extends LocalizedException
{
    public function __construct(?Phrase $phrase = null, ?\Throwable $cause = null, int $code = 0)
    {
        parent::__construct($phrase ?? new Phrase('No such entity.'), $cause, $code);
    }

    public static function singleField(string $fieldName, mixed $fieldValue): self
    {
        return new self(
            new Phrase('No such entity with %fieldName = %fieldValue', [
                'fieldName' => $fieldName,
                'fieldValue' => $fieldValue,
            ])
        );
    }

    public static function doubleField(
        string $fieldName,
        mixed $fieldValue,
        string $secondFieldName,
        mixed $secondFieldValue
    ): self {
        return new self(
            new Phrase('No such entity with %fieldName = %fieldValue, %field2Name = %field2Value', [
                'fieldName' => $fieldName,
                'fieldValue' => $fieldValue,
                'field2Name' => $secondFieldName,
                'field2Value' => $secondFieldValue,
            ])
        );
    }
}
