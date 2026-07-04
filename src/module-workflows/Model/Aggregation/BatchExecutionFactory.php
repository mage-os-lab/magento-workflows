<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;

/**
 * Production BatchExecutionFactoryInterface: builds the batch execution row the
 * same way the dispatcher builds a per-entity one, but with entity_id = 0 and
 * the batch trigger context, then persists it (pending) and returns its id.
 */
class BatchExecutionFactory implements BatchExecutionFactoryInterface
{
    public function __construct(
        private readonly WorkflowExecutionInterfaceFactory $executionFactory,
        private readonly WorkflowExecutionRepositoryInterface $executionRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function create(WorkflowInterface $workflow, array $batchContext, int $storeId): int
    {
        $context = [
            'trigger' => $batchContext,
            'steps' => new \stdClass(),
            'workflow' => [
                'id' => (int) $workflow->getWorkflowId(),
                'name' => $workflow->getName(),
                'version' => $workflow->getVersion(),
                'entity_type' => $workflow->getEntityType(),
            ],
        ];

        /** @var WorkflowExecutionInterface $execution */
        $execution = $this->executionFactory->create();
        $execution->setUuid($this->generateUuidV4());
        $execution->setWorkflowId((int) $workflow->getWorkflowId());
        $execution->setWorkflowVersion($workflow->getVersion());
        $execution->setDefinitionSnapshot($workflow->getDefinition());
        $execution->setEntityId(0);
        $execution->setStoreId($storeId);
        $execution->setStatus(WorkflowExecutionInterface::STATUS_PENDING);
        $execution->setContext(json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $execution->setChainDepth(0);
        $execution->setCurrentStep(null);

        $execution = $this->executionRepository->save($execution);
        return (int) $execution->getExecutionId();
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
