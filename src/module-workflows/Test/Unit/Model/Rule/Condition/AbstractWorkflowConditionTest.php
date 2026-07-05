<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule\Condition;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;
use PHPUnit\Framework\TestCase;

/**
 * Tests the private helper methods of AbstractWorkflowCondition.
 * The class is abstract, so we test via a concrete subclass.
 */
class AbstractWorkflowConditionTest extends TestCase
{
    private AbstractWorkflowCondition $condition;

    public function setUp(): void
    {
        // Use a minimal concrete subclass for testing
        // Since AbstractCondition extends DataObject, we can instantiate directly
        $this->condition = new class extends AbstractWorkflowCondition {
            public function __construct() {
                parent::__construct([]);
            }
            public function loadAttributeOptions() { return $this; }
            public function getInputType() { return 'string'; }
            public function getValueElementType() { return 'text'; }
        };
    }

    /**
     * Test isRelativeDateValue matches '-30 days'
     */
    public function testIsRelativeDateValueNegativeDays(): void
    {
        $result = $this->invokeIsRelativeDateValue('-30 days');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches '+2 weeks'
     */
    public function testIsRelativeDateValuePositiveWeeks(): void
    {
        $result = $this->invokeIsRelativeDateValue('+2 weeks');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches with spaces around operator
     */
    public function testIsRelativeDateValueSpacesAroundOperator(): void
    {
        $result = $this->invokeIsRelativeDateValue('- 30 days');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches '+ 2 weeks'
     */
    public function testIsRelativeDateValuePlusWithSpaces(): void
    {
        $result = $this->invokeIsRelativeDateValue('+ 2 weeks');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue rejects absolute date string
     */
    public function testIsRelativeDateValueRejectsAbsoluteDate(): void
    {
        $result = $this->invokeIsRelativeDateValue('2026-01-01');
        $this->assertFalse($result);
    }

    /**
     * Test isRelativeDateValue rejects 'yesterday'
     */
    public function testIsRelativeDateValueRejectsYesterday(): void
    {
        $result = $this->invokeIsRelativeDateValue('yesterday');
        $this->assertFalse($result);
    }

    /**
     * Test isRelativeDateValue rejects '-30days' (no space)
     */
    public function testIsRelativeDateValueRejectsNoSpace(): void
    {
        $result = $this->invokeIsRelativeDateValue('-30days');
        $this->assertFalse($result);
    }

    /**
     * Test isRelativeDateValue matches singular 'day'
     */
    public function testIsRelativeDateValueSingularDay(): void
    {
        $result = $this->invokeIsRelativeDateValue('-1 day');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches plural 'days'
     */
    public function testIsRelativeDateValuePluralDays(): void
    {
        $result = $this->invokeIsRelativeDateValue('-1 days');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches 'hour'
     */
    public function testIsRelativeDateValueHour(): void
    {
        $result = $this->invokeIsRelativeDateValue('-1 hour');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches 'month'
     */
    public function testIsRelativeDateValueMonth(): void
    {
        $result = $this->invokeIsRelativeDateValue('+1 month');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue matches 'year'
     */
    public function testIsRelativeDateValueYear(): void
    {
        $result = $this->invokeIsRelativeDateValue('-1 year');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue case-insensitive for unit
     */
    public function testIsRelativeDateValueCaseInsensitive(): void
    {
        $result = $this->invokeIsRelativeDateValue('-30 DAYS');
        $this->assertTrue($result);
    }

    /**
     * Test isRelativeDateValue rejects null
     */
    public function testIsRelativeDateValueRejectsNull(): void
    {
        $result = $this->invokeIsRelativeDateValue(null);
        $this->assertFalse($result);
    }

    /**
     * Test isRelativeDateValue rejects empty string
     */
    public function testIsRelativeDateValueRejectsEmpty(): void
    {
        $result = $this->invokeIsRelativeDateValue('');
        $this->assertFalse($result);
    }

    /**
     * Test resolveRelativeDate resolves '-30 days' correctly
     */
    public function testResolveRelativeDateNegativeDays(): void
    {
        $result = $this->invokeResolveRelativeDate('-30 days');
        // Result should be a date string in Y-m-d format, 30 days ago
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}$/', $result) === 1, "Result should match Y-m-d format: $result");
        // Verify it's roughly 30 days ago
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $thirtyDaysAgo = $now->modify('-30 days')->format('Y-m-d');
        $this->assertSame($thirtyDaysAgo, $result);
    }

    /**
     * Test resolveRelativeDate resolves '+2 weeks'
     */
    public function testResolveRelativeDatePositiveWeeks(): void
    {
        $result = $this->invokeResolveRelativeDate('+2 weeks');
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}$/', $result) === 1, "Result should match Y-m-d format: $result");
        // Verify it's roughly 2 weeks in the future
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $twoWeeksLater = $now->modify('+2 weeks')->format('Y-m-d');
        $this->assertSame($twoWeeksLater, $result);
    }

    /**
     * Test resolveRelativeDate normalizes spaces around operator
     */
    public function testResolveRelativeDateNormalizesSpaces(): void
    {
        $result = $this->invokeResolveRelativeDate('- 30 days');
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}$/', $result) === 1, "Result should match Y-m-d format: $result");
        // Should match the result of -30 days
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $thirtyDaysAgo = $now->modify('-30 days')->format('Y-m-d');
        $this->assertSame($thirtyDaysAgo, $result);
    }

    /**
     * Test resolveRelativeDate returns expression unchanged if it's invalid
     */
    public function testResolveRelativeDateInvalidExpression(): void
    {
        $result = $this->invokeResolveRelativeDate('garbage');
        // Should return the expression as-is since strtotime fails
        $this->assertSame('garbage', $result);
    }

    /**
     * Test resolveRelativeDate with trimmed input
     */
    public function testResolveRelativeDateTrimsInput(): void
    {
        $result = $this->invokeResolveRelativeDate('  -30 days  ');
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}$/', $result) === 1, "Result should match Y-m-d format: $result");
    }

    /**
     * Test validateAttribute returns false for null when operator is '=='
     */
    public function testValidateAttributeNullWithEqualsOperator(): void
    {
        $this->condition->setOperator('==');
        $result = $this->condition->validateAttribute(null);
        $this->assertFalse($result);
    }

    /**
     * Test validateAttribute returns true for null when operator is '!='
     */
    public function testValidateAttributeNullWithNotEqualsOperator(): void
    {
        $this->condition->setOperator('!=');
        $result = $this->condition->validateAttribute(null);
        $this->assertTrue($result);
    }

    /**
     * Test validateAttribute returns true for null when operator is '!{}'
     */
    public function testValidateAttributeNullWithNotContainsOperator(): void
    {
        $this->condition->setOperator('!{}');
        $result = $this->condition->validateAttribute(null);
        $this->assertTrue($result);
    }

    /**
     * Test validateAttribute returns true for null when operator is '!()'
     */
    public function testValidateAttributeNullWithNotInOperator(): void
    {
        $this->condition->setOperator('!()');
        $result = $this->condition->validateAttribute(null);
        $this->assertTrue($result);
    }

    /**
     * Test validate with missing attribute returns false
     */
    public function testValidateEmptyAttribute(): void
    {
        $this->condition->setAttribute('');
        $model = new DataObject(['key' => 'value']);
        $result = $this->condition->validate($model);
        $this->assertFalse($result);
    }

    /**
     * Invoke the private isRelativeDateValue method via reflection
     */
    private function invokeIsRelativeDateValue(mixed $value): bool
    {
        $reflection = new \ReflectionClass(AbstractWorkflowCondition::class);
        $method = $reflection->getMethod('isRelativeDateValue');
        return $method->invoke($this->condition, $value);
    }

    /**
     * Invoke the private resolveRelativeDate method via reflection
     */
    private function invokeResolveRelativeDate(string $expression): string
    {
        $reflection = new \ReflectionClass(AbstractWorkflowCondition::class);
        $method = $reflection->getMethod('resolveRelativeDate');
        return $method->invoke($this->condition, $expression);
    }

}
