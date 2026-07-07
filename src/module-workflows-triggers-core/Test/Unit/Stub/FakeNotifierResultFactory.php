<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use MageOS\AsyncEvents\Helper\NotifierResult;
use MageOS\AsyncEvents\Helper\NotifierResultFactory;

/**
 * Test double for the generated NotifierResultFactory: creates real
 * NotifierResult instances (plain DataObject-backed) without an object
 * manager.
 */
class FakeNotifierResultFactory extends NotifierResultFactory
{
    public function __construct()
    {
    }

    /**
     * @param array $data
     * @return NotifierResult
     */
    public function create(array $data = [])
    {
        return new NotifierResult($data);
    }
}
