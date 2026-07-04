<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Execution;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\CollectionFactory;

/**
 * Renders one execution plus its per-step timeline (docs/09 -- drill-down timeline).
 *
 * The step rows come from MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\Collection
 * (peer, same naming convention as the Workflow/WorkflowExecution collections; no dedicated
 * step repository is part of the given peer context).
 */
class View extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly CollectionFactory $stepCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getExecution(): ?WorkflowExecutionInterface
    {
        return $this->coreRegistry->registry('mageos_current_execution');
    }

    public function isDryRun(): bool
    {
        $execution = $this->getExecution();
        return $execution !== null && $execution->getMode() === WorkflowExecutionInterface::MODE_DRY_RUN;
    }

    public function getWorkflowName(): string
    {
        $execution = $this->getExecution();
        if (!$execution) {
            return '';
        }
        try {
            return $this->workflowRepository->getById($execution->getWorkflowId())->getName();
        } catch (NoSuchEntityException $e) {
            return (string) __('Workflow #%1 (deleted)', $execution->getWorkflowId());
        }
    }

    /**
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface[]
     */
    public function getSteps(): array
    {
        $execution = $this->getExecution();
        if (!$execution || !$execution->getExecutionId()) {
            return [];
        }

        $collection = $this->stepCollectionFactory->create();
        $collection->addFieldToFilter('execution_id', $execution->getExecutionId());
        $collection->setOrder('step_execution_id', 'ASC');

        return $collection->getItems();
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextData(): array
    {
        $execution = $this->getExecution();
        if (!$execution || !$execution->getContext()) {
            return [];
        }
        $decoded = json_decode((string) $execution->getContext(), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function formatJson(?string $json): string
    {
        if ($json === null || trim($json) === '') {
            return '';
        }
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
