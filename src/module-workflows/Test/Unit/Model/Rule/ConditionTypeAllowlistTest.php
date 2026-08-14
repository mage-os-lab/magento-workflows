<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule;

use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\ConditionCombinePool;
use MageOS\Workflows\Model\Rule\ConditionLeafPool;
use MageOS\Workflows\Model\Rule\ConditionTypeAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * The `type` gate for stored condition trees: registered pool classes and the
 * engine's own built-ins pass; an existing class OUTSIDE that surface is
 * rejected (its constructor would fire inside the condition factory before
 * any instanceof check could object); a non-class marker string passes — it
 * instantiates nothing and real trees carry them.
 */
class ConditionTypeAllowlistTest extends TestCase
{
    public function setUp(): void
    {
        \class_exists(ConditionLeafPoolTest::class);
    }

    private function allowlist(array $combines = [], array $leaves = [], array $additional = []): ConditionTypeAllowlist
    {
        $om = new FakeLeafObjectManager([]);
        return new ConditionTypeAllowlist(
            new ConditionCombinePool($om, $combines),
            new ConditionLeafPool($om, $leaves),
            $additional
        );
    }

    public function testRegisteredCombineAndLeafClassesAreAllowed(): void
    {
        $allowlist = $this->allowlist(
            ['sales_order' => \ArrayObject::class],
            ['sales_order' => \SplStack::class]
        );

        $this->assertTrue($allowlist->isAllowed(\ArrayObject::class));
        $this->assertTrue($allowlist->isAllowed(\SplStack::class));
    }

    public function testEngineBuiltInsAreAlwaysAllowed(): void
    {
        $allowlist = $this->allowlist();

        $this->assertTrue($allowlist->isAllowed(RelatedEntityCombine::class));
        $this->assertTrue($allowlist->isAllowed(TriggerData::class));
    }

    public function testExistingClassOutsideTheSurfaceIsRejected(): void
    {
        $this->assertFalse($this->allowlist()->isAllowed(\ArrayObject::class));
        // Leading backslash must not bypass the check.
        $this->assertFalse($this->allowlist()->isAllowed('\\' . \ArrayObject::class));
    }

    public function testNonClassMarkerStringsPass(): void
    {
        $allowlist = $this->allowlist();

        $this->assertTrue($allowlist->isAllowed('combine'));
        $this->assertTrue($allowlist->isAllowed('No\\Such\\Condition\\Class'));
    }

    public function testAdditionalClassesExtendTheSurface(): void
    {
        $allowlist = $this->allowlist([], [], [\SplQueue::class]);

        $this->assertTrue($allowlist->isAllowed(\SplQueue::class));
        // Registered with or without a leading backslash, matched either way.
        $this->assertTrue($this->allowlist([], [], ['\\SplQueue'])->isAllowed(\SplQueue::class));
    }
}
