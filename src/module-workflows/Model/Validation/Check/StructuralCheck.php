<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Wraps Definition::fromJson: parse/shape errors become typed results
 * instead of raw exceptions. Must run first in the pool — every graph-aware
 * check downstream no-ops when the subject failed to parse.
 */
class StructuralCheck implements CheckInterface
{
    public const CODE_DEFINITION_INVALID = 'DEFINITION_INVALID';

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        if ($subject->getDefinition() !== null) {
            return [];
        }
        return [
            ValidationMessage::error(
                self::CODE_DEFINITION_INVALID,
                (string) __('The workflow definition is invalid: %1', (string) $subject->getParseError())
            ),
        ];
    }
}
