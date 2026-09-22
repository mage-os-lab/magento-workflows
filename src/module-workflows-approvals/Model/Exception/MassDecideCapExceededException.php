<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Exception;

/**
 * The mass-decide selection cap was exceeded (docs/discovery/approval-gate.md
 * §6, borrowing the manual mass-run cap vocabulary — 10-security.md#manual-mass-run
 * / MageOS\Workflows\Model\Engine\Executor's guard config family). Thrown BEFORE
 * any row is processed — a run either fully respects the cap or does nothing.
 */
class MassDecideCapExceededException extends \RuntimeException
{
    public function __construct(private readonly int $selected, private readonly int $cap)
    {
        parent::__construct(sprintf(
            '%d task(s) selected, exceeding the mass-decide cap of %d. Narrow the selection and try again.',
            $selected,
            $cap
        ));
    }

    public function getSelected(): int
    {
        return $this->selected;
    }

    public function getCap(): int
    {
        return $this->cap;
    }
}
