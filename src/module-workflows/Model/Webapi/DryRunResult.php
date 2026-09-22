<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\DryRunResultInterface;
use MageOS\Workflows\Model\DryRun\Trace;

/**
 * Immutable webapi DTO for a dry-run (03), assembled from a {@see Trace}.
 */
class DryRunResult implements DryRunResultInterface
{
    /**
     * @param \MageOS\Workflows\Api\Data\ValidationMessageInterface[] $messages
     * @param \MageOS\Workflows\Api\Data\DryRunTraceStepInterface[] $steps
     */
    public function __construct(
        private readonly bool $valid,
        private readonly bool $skipped,
        private readonly bool $truncated,
        private readonly array $messages,
        private readonly array $steps
    ) {
    }

    public static function fromTrace(Trace $trace): self
    {
        return new self(
            !$trace->hasErrors(),
            $trace->isSkipped(),
            $trace->isTruncated(),
            $trace->getValidation(),
            array_map(
                static fn ($step): DryRunTraceStep => DryRunTraceStep::fromTraceStep($step),
                $trace->getSteps()
            )
        );
    }

    public function getValid(): bool
    {
        return $this->valid;
    }

    public function getSkipped(): bool
    {
        return $this->skipped;
    }

    public function getTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @inheritDoc
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @inheritDoc
     */
    public function getSteps(): array
    {
        return $this->steps;
    }
}
