<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * One pass of the save-time validation pipeline (F2). Checks are registered
 * into WorkflowValidator's ordered pool via di.xml. A check that needs the
 * parsed graph must no-op (return []) when the subject failed to parse —
 * StructuralCheck owns reporting that failure.
 */
interface CheckInterface
{
    /**
     * @return ValidationMessageInterface[]
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array;
}
