<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * Response shape of POST /V1/workflows/validate (F6): the F2 pipeline's
 * findings plus the plain-language rendering of the posted definition.
 *
 * @api
 */
interface DefinitionValidationResultInterface
{
    /**
     * True when no error-severity findings exist (warnings do not invalidate)
     */
    public function getValid(): bool;

    /**
     * @return \MageOS\Workflows\Api\Data\ValidationMessageInterface[]
     */
    public function getMessages(): array;

    /**
     * Merchant-readable summary sentence of the posted definition
     */
    public function getPlainLanguage(): string;
}
