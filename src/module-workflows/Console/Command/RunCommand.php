<?php

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\Serialize\SerializerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually dispatches a workflow execution (docs/05-triggers.md#manual-triggers).
 *
 * "Spawns a standard execution with trigger_type=manual recorded. This doubles as the
 * developer test harness during development and the merchant's test harness after."
 */
class RunCommand extends Command
{
    private const ARG_WORKFLOW_ID = 'workflow_id';
    private const OPT_ENTITY_ID = 'entity-id';
    private const OPT_PAYLOAD = 'payload';

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly SerializerInterface $serializer,
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
}
