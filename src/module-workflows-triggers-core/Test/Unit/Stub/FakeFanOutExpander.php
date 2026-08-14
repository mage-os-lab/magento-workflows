<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use MageOS\Workflows\Model\Engine\FanOutExpander;
use MageOS\Workflows\Model\Engine\FanOutResult;

/**
 * FanOutExpander stand-in. Default behavior (null result) is the
 * overwhelmingly common no-fan_out-clause case, which routes the notifier
 * into its ordinary single-dispatch branch.
 */
class FakeFanOutExpander extends FanOutExpander
{
    /** @var array<int, array{workflowId: int, data: array, eventName: string, traceUuid: ?string}> */
    public array $calls = [];

    public ?FanOutResult $result = null;

    public ?\Throwable $throws = null;

    public function __construct()
    {
    }

    public function expand(
        int $workflowId,
        array $sourceData,
        string $eventName,
        ?string $traceUuid,
        int $chainDepth = 0
    ): ?FanOutResult {
        $this->calls[] = [
            'workflowId' => $workflowId,
            'data' => $sourceData,
            'eventName' => $eventName,
            'traceUuid' => $traceUuid,
            'chainDepth' => $chainDepth,
        ];
        if ($this->throws !== null) {
            throw $this->throws;
        }
        return $this->result;
    }
}
