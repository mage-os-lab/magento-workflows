<?php

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\DryRun\DryRunRequest;
use MageOS\Workflows\Model\DryRun\DryRunService;
use MageOS\Workflows\Model\DryRun\Trace;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually dispatches a workflow execution (docs/05-triggers.md#manual-triggers).
 *
 * "Spawns a standard execution with trigger_type=manual recorded. This doubles as the
 * developer test harness during development and the merchant's test harness after."
 *
 * With --dry-run the command instead previews a SAVED workflow synchronously
 * (03): no dispatch, no queue, no side effects — it renders the trace as a
 * table. The DryRunService is injected alongside the dispatcher; the non-flag
 * path is untouched. Unsaved-definition dry-run is a REST/admin surface, not CLI.
 */
class RunCommand extends Command
{
    private const ARG_WORKFLOW_ID = 'workflow_id';
    private const OPT_ENTITY_ID = 'entity-id';
    private const OPT_PAYLOAD = 'payload';
    private const OPT_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly SerializerInterface $serializer,
        private readonly DryRunService $dryRunService,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:run')
            ->setDescription('Manually dispatch a workflow execution (trigger_type=manual).')
            ->addArgument(self::ARG_WORKFLOW_ID, InputArgument::REQUIRED, 'Workflow ID to dispatch')
            ->addOption(
                self::OPT_ENTITY_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Subject entity ID placed into the manual trigger payload'
            )
            ->addOption(
                self::OPT_PAYLOAD,
                null,
                InputOption::VALUE_REQUIRED,
                'Extra trigger payload as a JSON object, merged with entity_id',
                '{}'
            )
            ->addOption(
                self::OPT_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Preview the saved workflow against the entity without dispatching (no side effects)'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = (int) $input->getArgument(self::ARG_WORKFLOW_ID);

        $entityIdOption = $input->getOption(self::OPT_ENTITY_ID);
        if ($entityIdOption === null || $entityIdOption === '' || !is_numeric($entityIdOption)) {
            $output->writeln('<error>--entity-id is required and must be numeric</error>');
            return Command::FAILURE;
        }
        $entityId = (int) $entityIdOption;

        if ($input->getOption(self::OPT_DRY_RUN)) {
            return $this->executeDryRun($workflowId, $entityId, $output);
        }

        $payloadJson = (string) $input->getOption(self::OPT_PAYLOAD);
        try {
            $payload = $this->serializer->unserialize($payloadJson);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>--payload is not valid JSON: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
        if (!is_array($payload)) {
            $output->writeln('<error>--payload must be a JSON object</error>');
            return Command::FAILURE;
        }

        $payload['entity_id'] = $entityId;

        $execution = $this->dispatcher->dispatch(
            $workflowId,
            $payload,
            WorkflowInterface::TRIGGER_TYPE_MANUAL
        );

        if ($execution === null) {
            $output->writeln('suppressed/skipped');
            return Command::SUCCESS;
        }

        $output->writeln($execution->getUuid());
        return Command::SUCCESS;
    }

    /**
     * Preview a saved workflow (by id only) and render the trace as a table.
     */
    private function executeDryRun(int $workflowId, int $entityId, OutputInterface $output): int
    {
        try {
            $workflow = $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException $e) {
            $output->writeln(sprintf('<error>Workflow %d does not exist</error>', $workflowId));
            return Command::FAILURE;
        }

        $trace = $this->dryRunService->run(new DryRunRequest(
            $workflow->getDefinition(),
            $workflow->getConditionsSerialized(),
            $workflow->getEntityType(),
            $entityId,
            null,
            (int) $workflow->getWorkflowId(),
            $workflow->getName()
        ));

        return $this->renderTrace($trace, $output);
    }

    private function renderTrace(Trace $trace, OutputInterface $output): int
    {
        foreach ($trace->getValidation() as $message) {
            $tag = $message->getSeverity() === ValidationMessageInterface::SEVERITY_ERROR ? 'error' : 'comment';
            $output->writeln(sprintf('<%s>%s: %s</%s>', $tag, $message->getCode(), $message->getMessage(), $tag));
        }

        if ($trace->hasErrors()) {
            return Command::FAILURE;
        }

        if ($trace->isSkipped()) {
            $output->writeln('<comment>Workflow would be skipped: root conditions did not match this entity.</comment>');
            return Command::SUCCESS;
        }

        if ($trace->getSteps() === []) {
            $output->writeln('<comment>The workflow has no steps to run.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Step', 'Type', 'Status', 'Would / Summary', 'Edge']);
        foreach ($trace->getSteps() as $step) {
            $table->addRow([
                $step->getStepKey(),
                $step->getType(),
                $step->getStatus()->value,
                (string) ($step->getWould() ?? ''),
                (string) ($step->getEdgeTaken() ?? ''),
            ]);
        }
        $table->render();

        if ($trace->isTruncated()) {
            $output->writeln('<comment>Trace truncated at the step-visit cap; some paths were not explored.</comment>');
        }

        return Command::SUCCESS;
    }
}
