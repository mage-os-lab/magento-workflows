<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

use MageOS\Workflows\Model\Definition\Definition;

/**
 * What is being validated (F2): the raw definition JSON plus the root
 * conditions tree. Parsing is memoized so the check pool shares one
 * Definition instance; a parse failure is surfaced by StructuralCheck as a
 * typed result, and getDefinition() stays null for downstream checks.
 */
class ValidationSubject
{
    private bool $parsed = false;

    private ?Definition $definition = null;

    private ?string $parseError = null;

    public function __construct(
        private readonly string $definitionJson,
        private readonly ?string $conditionsSerialized = null
    ) {
    }

    public function getDefinitionJson(): string
    {
        return $this->definitionJson;
    }

    public function getConditionsSerialized(): ?string
    {
        return $this->conditionsSerialized;
    }

    /**
     * Parsed definition, or null when the JSON is structurally invalid
     */
    public function getDefinition(): ?Definition
    {
        $this->parse();
        return $this->definition;
    }

    /**
     * Parse failure message, or null when the definition parsed cleanly
     */
    public function getParseError(): ?string
    {
        $this->parse();
        return $this->parseError;
    }

    private function parse(): void
    {
        if ($this->parsed) {
            return;
        }
        $this->parsed = true;
        try {
            $this->definition = Definition::fromJson($this->definitionJson);
        } catch (\InvalidArgumentException $e) {
            $this->parseError = $e->getMessage();
        }
    }
}
