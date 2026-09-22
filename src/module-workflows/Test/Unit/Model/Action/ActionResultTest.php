<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Action;

use MageOS\Workflows\Model\Action\ActionResult;
use PHPUnit\Framework\TestCase;

class ActionResultTest extends TestCase
{
    public function testSuccessFactoryDefaults(): void
    {
        $result = ActionResult::success();

        $this->assertSame(ActionResult::STATUS_SUCCESS, $result->getStatus());
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertEquals([], $result->getOutput());
        $this->assertFalse($result->isRetryable());
        $this->assertNull($result->getError());
    }

    public function testSuccessFactoryWithOutput(): void
    {
        $result = ActionResult::success(['order_id' => 123]);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(['order_id' => 123], $result->getOutput());
    }

    public function testSkippedFactoryWithoutReason(): void
    {
        $result = ActionResult::skipped();

        $this->assertSame(ActionResult::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertEquals([], $result->getOutput());
        $this->assertFalse($result->isRetryable());
    }

    public function testSkippedFactoryWithReason(): void
    {
        $result = ActionResult::skipped('condition not met');

        $this->assertSame(ActionResult::STATUS_SKIPPED, $result->getStatus());
        $this->assertEquals(['reason' => 'condition not met'], $result->getOutput());
    }

    public function testFailureFactoryDefaultsToNotRetryable(): void
    {
        $result = ActionResult::failure('webhook timed out');

        $this->assertSame(ActionResult::STATUS_FAILURE, $result->getStatus());
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isRetryable());
        $this->assertSame('webhook timed out', $result->getError());
        $this->assertEquals([], $result->getOutput());
    }

    public function testFailureFactoryRetryableFlagAndOutput(): void
    {
        $result = ActionResult::failure('rate limited', true, ['attempt' => 3]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertSame('rate limited', $result->getError());
        $this->assertEquals(['attempt' => 3], $result->getOutput());
    }
}
