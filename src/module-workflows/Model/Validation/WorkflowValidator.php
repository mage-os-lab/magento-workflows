<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

use MageOS\Workflows\Model\Validation\Check\CheckInterface;

/**
 * Orchestrator of the save-time validation pipeline (F2): runs the
 * di.xml-registered, ordered check pool over one subject and aggregates the
 * findings. When the definition fails to parse, the run stops after the
 * parse failure is reported — graph-aware checks cannot say anything useful
 * about an unparseable document.
 *
 * Every authoring path funnels through this pipeline via the before-plugin
 * on WorkflowRepository::save (admin Save, REST, CLI import, gallery); the
 * executor never calls the repository (it parses definition_snapshot
 * directly), so validation structurally cannot leak into the retroactivity
 * trap (docs/discovery/branching.md §2).
 */
class WorkflowValidator
{
    /**
     * @param CheckInterface[] $checks ordered check pool (di.xml)
     */
    public function __construct(
        private readonly array $checks = []
    ) {
        foreach ($this->checks as $name => $check) {
            if (!$check instanceof CheckInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Validation check "%s" must implement %s', (string) $name, CheckInterface::class)
                );
            }
        }
    }

    public function validate(ValidationSubject $subject, ValidationContext $context): ValidationResult
    {
        $messages = [];
        foreach ($this->checks as $check) {
            foreach ($check->check($subject, $context) as $message) {
                $messages[] = $message;
            }
            if ($subject->getDefinition() === null) {
                break;
            }
        }
        return new ValidationResult($messages);
    }
}
