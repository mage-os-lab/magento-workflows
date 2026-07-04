<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Import;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Validation\ValidationResult;

/**
 * Outcome of a WorkflowImporter::import() run: the persisted workflow plus
 * the validation result, so the calling surface (CLI, gallery) can show the
 * non-blocking warnings that traveled with the save.
 */
class ImportResult
{
    public function __construct(
        private readonly WorkflowInterface $workflow,
        private readonly ValidationResult $validationResult
    ) {
    }

    public function getWorkflow(): WorkflowInterface
    {
        return $this->workflow;
    }

    public function getValidationResult(): ValidationResult
    {
        return $this->validationResult;
    }
}
