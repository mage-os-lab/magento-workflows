<?php

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the available workflow templates (06). Shows each template's code,
 * category, version, and compatibility with this install (the same
 * CompatibilityChecker the gallery greys cards out with).
 */
class TemplateListCommand extends Command
{
    public function __construct(
        private readonly TemplateSourceInterface $templateSource,
        private readonly CompatibilityChecker $compatibilityChecker,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:template:list')
            ->setDescription('List the bundled workflow templates and their compatibility with this install.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summaries = $this->templateSource->list();
        if ($summaries === []) {
            $output->writeln('<comment>No workflow templates are available.</comment>');
            return Command::SUCCESS;
        }

        usort(
            $summaries,
            static fn ($a, $b): int => [$a->getCategory(), $a->getCode()] <=> [$b->getCategory(), $b->getCode()]
        );

        foreach ($summaries as $summary) {
            $compat = $this->compatibilityChecker->check($summary, LocalizedText::DEFAULT_LOCALE);
            $status = $compat->isCompatible()
                ? '<info>compatible</info>'
                : '<comment>unavailable</comment>';
            $output->writeln(sprintf(
                '%s  <info>%s</info> v%s  [%s]  %s',
                $status,
                $summary->getCode(),
                $summary->getVersion(),
                $summary->getCategory(),
                $summary->getTitle()
            ));
            if (!$compat->isCompatible()) {
                foreach ($compat->getMessages() as $message) {
                    $output->writeln('    <comment>- ' . $message . '</comment>');
                }
            }
        }

        return Command::SUCCESS;
    }
}
