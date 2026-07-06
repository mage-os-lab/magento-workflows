<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model;

use MageOS\WorkflowsScheduler\Model\ConditionToSearchCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConditionToSearchCriteria operator mapping.
 *
 * NOTE: The convert() method and buildFilters() method depend on
 * SearchCriteriaBuilder/FilterBuilder which require complex infrastructure
 * (builder chains, filter groups, SearchCriteria interfaces) to stub properly.
 * These tests focus on the operator mapping constant, which is the most
 * valuable testable part via reflection. Condition conversion logic is
 * integration-level testing that's better handled with real API builders.
 */
class ConditionToSearchCriteriaTest extends TestCase
{
    /**
     * Test operator map: == maps to 'eq'
     */
    public function testOperatorMapEqualsToEq(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('==', $map);
        $this->assertSame('eq', $map['==']);
    }

    /**
     * Test operator map: != maps to 'neq'
     */
    public function testOperatorMapNotEqualsToNeq(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('!=', $map);
        $this->assertSame('neq', $map['!=']);
    }

    /**
     * Test operator map: >= maps to 'gteq'
     */
    public function testOperatorMapGreaterOrEqualToGteq(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('>=', $map);
        $this->assertSame('gteq', $map['>=']);
    }

    /**
     * Test operator map: <= maps to 'lteq'
     */
    public function testOperatorMapLessOrEqualToLteq(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('<=', $map);
        $this->assertSame('lteq', $map['<=']);
    }

    /**
     * Test operator map: > maps to 'gt'
     */
    public function testOperatorMapGreaterToGt(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('>', $map);
        $this->assertSame('gt', $map['>']);
    }

    /**
     * Test operator map: < maps to 'lt'
     */
    public function testOperatorMapLessToLt(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('<', $map);
        $this->assertSame('lt', $map['<']);
    }

    /**
     * Test operator map: () maps to 'in' (array contains)
     */
    public function testOperatorMapInListToIn(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('()', $map);
        $this->assertSame('in', $map['()']);
    }

    /**
     * Test operator map: !() maps to 'nin' (not in array)
     */
    public function testOperatorMapNotInListToNin(): void
    {
        $map = $this->getOperatorMap();
        $this->assertArrayHasKey('!()', $map);
        $this->assertSame('nin', $map['!()']);
    }

    /**
     * Test operator map is complete: 8 operators
     */
    public function testOperatorMapHasEightEntries(): void
    {
        $map = $this->getOperatorMap();
        $this->assertCount(8, $map);
    }

    /**
     * Test operator map keys are the classic Magento rule operators
     */
    public function testOperatorMapKeysAreRuleOperators(): void
    {
        $map = $this->getOperatorMap();
        $expected_keys = ['==', '!=', '>=', '<=', '>', '<', '()', '!()'];
        sort($expected_keys);
        $actual_keys = array_keys($map);
        sort($actual_keys);
        $this->assertEquals($expected_keys, $actual_keys);
    }

    /**
     * Extract the OPERATOR_MAP constant via reflection
     */
    private function getOperatorMap(): array
    {
        $reflection = new \ReflectionClass(ConditionToSearchCriteria::class);
        $constant = $reflection->getConstant('OPERATOR_MAP');
        return is_array($constant) ? $constant : [];
    }
}
