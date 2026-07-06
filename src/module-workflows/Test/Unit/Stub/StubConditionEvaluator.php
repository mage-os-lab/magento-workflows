<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;

/**
 * Controllable stand-in for the production ConditionEvaluator: canned boolean
 * results keyed by the serialized tree, and an opt-in throw list to exercise
 * the unevaluable-condition (both-edges) path. The parent constructor is
 * deliberately not invoked — only the two public evaluation methods the walker
 * calls are overridden.
 */
class StubConditionEvaluator extends ConditionEvaluator
{
    /** @var array<string, bool> */
    private array $results;

    /** @var string[] */
    private array $throwFor;

    /** @var array<int, array{serialized: string, revalidate: bool}> */
    public array $calls = [];

    /**
     * @param array<string, bool> $results serialized tree => result
     * @param string[] $throwFor serialized trees that should throw
     */
    public function __construct(array $results = [], array $throwFor = [])
    {
        $this->results = $results;
        $this->throwFor = $throwFor;
    }

    public function evaluateSerialized(
        string $conditionsSerialized,
        string $entityType,
        ExecutionContext $ctx,
        bool $revalidateEntity
    ): bool {
        $this->calls[] = ['serialized' => $conditionsSerialized, 'revalidate' => $revalidateEntity];
        if (in_array($conditionsSerialized, $this->throwFor, true)) {
            throw new \InvalidArgumentException('Unevaluable condition tree');
        }
        return $this->results[$conditionsSerialized] ?? true;
    }
}
