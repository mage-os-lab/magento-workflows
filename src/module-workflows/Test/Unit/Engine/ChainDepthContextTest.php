<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Engine;

use MageOS\Workflows\Model\Engine\ChainDepthContext;
use PHPUnit\Framework\TestCase;

class ChainDepthContextTest extends TestCase
{
    public function testInactiveOutsideAnyExecution(): void
    {
        $context = new ChainDepthContext();

        $this->assertFalse($context->isActive());
        $this->assertSame(0, $context->dispatchDepth());
    }

    public function testEnterMarksDispatchDepthAsExecutionDepthPlusOne(): void
    {
        $context = new ChainDepthContext();

        $context->enter(0);

        $this->assertTrue($context->isActive());
        $this->assertSame(1, $context->dispatchDepth());
    }

    public function testDeeperExecutionRaisesDispatchDepth(): void
    {
        $context = new ChainDepthContext();

        $context->enter(2);

        $this->assertSame(3, $context->dispatchDepth());
    }

    public function testRestoreUnwindsToThePreviousMarker(): void
    {
        $context = new ChainDepthContext();

        $outer = $context->enter(0);
        $inner = $context->enter(4);
        $this->assertSame(5, $context->dispatchDepth());

        $context->restore($inner);
        $this->assertSame(1, $context->dispatchDepth());

        $context->restore($outer);
        $this->assertFalse($context->isActive());
        $this->assertSame(0, $context->dispatchDepth());
    }
}
