<?php
declare(strict_types=1);

namespace MageOS\Workflows\Plugin;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\ValidatorException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow as WorkflowResource;
use MageOS\Workflows\Model\Validation\Check\ActionAuthorizationCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationContextResolver;
use MageOS\Workflows\Model\Validation\ValidationResultRegistry;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Model\WorkflowFactory;
use Psr\Log\LoggerInterface;

/**
 * The single validation chokepoint (F2): every authoring path funnels
 * through WorkflowRepositoryInterface::save (admin Save, REST, CLI import,
 * gallery), and the executor never calls it — it parses definition_snapshot
 * directly — so save-time validation structurally cannot re-judge in-flight
 * executions (the retroactivity trap, docs/discovery/branching.md §2).
 *
 * Required guard: validation runs ONLY when the definition or conditions
 * changed (the repository's isDefinitionChanged idiom). Status-only saves
 * (mass enable/disable) must not re-validate a stored definition, or
 * disabling a workflow whose action module was uninstalled becomes
 * impossible.
 *
 * REST tightening (deliberate, documented): POST/PUT /V1/workflows
 * previously performed no validation and no per-action ACL. With this
 * plugin, invalid definitions are rejected (HTTP 400) and unauthorized
 * action codes fail authorization — a breaking change for API clients that
 * relied on saving invalid payloads.
 *
 * Warnings never block: they land in the ValidationResultRegistry for the
 * calling surface (admin form messages, CLI import output) and are logged.
 */
class ValidateWorkflowOnSave
{
    public function __construct(
        private readonly WorkflowValidator $validator,
        private readonly ValidationContextResolver $contextResolver,
        private readonly WorkflowFactory $workflowFactory,
        private readonly WorkflowResource $workflowResource,
        private readonly ValidationResultRegistry $resultRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{0: WorkflowInterface}
     * @throws AuthorizationException when the author lacks a referenced action's ACL resource
     * @throws ValidatorException when the definition or conditions are invalid
     */
    public function beforeSave(WorkflowRepositoryInterface $subject, WorkflowInterface $workflow): array
    {
        if (!$this->definitionOrConditionsChanged($workflow)) {
            return [$workflow];
        }

        $result = $this->validator->validate(
            new ValidationSubject(
                $workflow->getDefinition(),
                $workflow->getConditionsSerialized(),
                $workflow->getTriggerType(),
                $workflow->getTriggerRef(),
                $workflow->getEntityType(),
                $workflow->getFanOut()
            ),
            $this->resolveContext($workflow)
        );
        $this->resultRegistry->set($result);

        foreach ($result->getWarnings() as $warning) {
            $this->logger->info(sprintf(
                'Workflow validation warning [%s]%s: %s',
                $warning->getCode(),
                $warning->getStepKey() !== null ? sprintf(' (step "%s")', $warning->getStepKey()) : '',
                $warning->getMessage()
            ));
        }

        if ($result->hasErrors()) {
            if ($result->hasErrorWithCode(ActionAuthorizationCheck::CODE_ACTION_UNAUTHORIZED)) {
                throw new AuthorizationException(__(
                    '%1',
                    implode(' ', $this->errorTexts($result->getErrors()))
                ));
            }
            throw new ValidatorException(__(
                'The workflow cannot be saved: %1',
                implode(' ', $this->errorTexts($result->getErrors()))
            ));
        }

        return [$workflow];
    }

    /**
     * The base ValidationContext (auth mode from area), refined with the
     * workflow's kind and entity type: a non-null aggregation column makes
     * this an 'aggregated' workflow, activating the restricted batch
     * ProfileCheck (05). The entity type lets that check resolve the trigger
     * snapshot shape for the in-snapshot condition constraint.
     */
    private function resolveContext(WorkflowInterface $workflow): ValidationContext
    {
        $base = $this->contextResolver->resolve();
        $kind = $workflow->getAggregation() !== null
            ? ValidationContext::KIND_AGGREGATED
            : ValidationContext::KIND_STANDARD;

        return new ValidationContext(
            $base->getAuthMode(),
            $kind,
            $base->isDryRun(),
            $workflow->getEntityType()
        );
    }

    /**
     * The isDefinitionChanged idiom: new workflows always validate; existing
     * ones only when definition or conditions differ from the stored row.
     */
    private function definitionOrConditionsChanged(WorkflowInterface $workflow): bool
    {
        $workflowId = $workflow->getWorkflowId();
        if (!$workflowId) {
            return true;
        }
        $prior = $this->workflowFactory->create();
        $this->workflowResource->load($prior, $workflowId);
        if (!$prior->getWorkflowId()) {
            return true;
        }
        return $prior->getDefinition() !== $workflow->getDefinition()
            || ($prior->getConditionsSerialized() ?? '') !== ($workflow->getConditionsSerialized() ?? '')
            // A fan-out clause change (relation/cap) must re-run the alignment
            // check even when the definition is untouched.
            || ($prior->getFanOut() ?? '') !== ($workflow->getFanOut() ?? '');
    }

    /**
     * @param \MageOS\Workflows\Api\Data\ValidationMessageInterface[] $errors
     * @return string[]
     */
    private function errorTexts(array $errors): array
    {
        return array_map(
            static fn ($error): string => rtrim($error->getMessage(), '.') . '.',
            $errors
        );
    }
}
