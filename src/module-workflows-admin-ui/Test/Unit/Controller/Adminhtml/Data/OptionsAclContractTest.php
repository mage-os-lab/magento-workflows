<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Data;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Data\Options;
use PHPUnit\Framework\TestCase;

/**
 * ACL + HTTP-method contract for the shared option-source feed. It is a
 * READ surface — the same-origin twin of GET /V1/workflows/meta/options,
 * delegating to the same core provider — so ::view is the gate, not ::manage:
 * an operator who may look at a workflow must be able to resolve the labels
 * behind its ids. It is also GET-only, which keeps it out of the admin
 * router's form-key path and matches what the REST route allows.
 *
 * The controller has a heavy Action\Context constructor, so both are asserted
 * by reflection on the class, not an instance.
 */
class OptionsAclContractTest extends TestCase
{
    public function testOptionsFeedIsGatedByView(): void
    {
        $this->assertSame(
            'MageOS_Workflows::view',
            Options::ADMIN_RESOURCE,
            'The option feed is read-only; gating it by ::manage would hide labels from viewers.'
        );
    }

    public function testOptionsFeedIsGetOnly(): void
    {
        $this->assertTrue(
            is_a(Options::class, HttpGetActionInterface::class, true),
            'The option feed must declare itself GET-only.'
        );
    }

    public function testOptionsFeedIsNotAWriteSurface(): void
    {
        $this->assertFalse(
            is_a(Options::class, HttpPostActionInterface::class, true),
            'A controller implementing both interfaces would accept POSTs the REST twin refuses.'
        );
    }
}
