<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use MageOS\AsyncEvents\Api\Data\AsyncEventInterfaceFactory;

/**
 * Test double for the generated AsyncEventInterfaceFactory: creates
 * FakeAsyncEvent instances without an object manager.
 */
class FakeAsyncEventFactory extends AsyncEventInterfaceFactory
{
    /** @var FakeAsyncEvent[] */
    public array $created = [];

    public function __construct()
    {
    }

    /**
     * @param array $data
     * @return FakeAsyncEvent
     */
    public function create(array $data = [])
    {
        $asyncEvent = new FakeAsyncEvent();
        $this->created[] = $asyncEvent;
        return $asyncEvent;
    }
}
