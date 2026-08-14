<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Webapi\ExecutionDetailRedactor;
use MageOS\Workflows\Model\Webapi\StepDetailRedactor;
use MageOS\Workflows\Model\Webapi\WorkflowExecutionReader;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflow-executions[/:id]: the security pin that the execution
 * `context` blob never leaves the server with a live credential in it.
 *
 * These routes used to bind straight to WorkflowExecutionRepositoryInterface,
 * which is also what the ENGINE loads executions through — so the fix had to be
 * a read model in front of the repository, not redaction inside it. Both halves
 * are asserted here: the context is masked on the way out, and the delegation
 * to the repository is otherwise transparent (total_count, criteria, the
 * fields the engine relies on).
 */
class WorkflowExecutionReaderTest extends TestCase
{
    private const SENTINEL = 'sk_live_SENTINEL_9f8e7d6c5b4a';

    /**
     * @param array<string, string> $secrets
     */
    private function redactor(array $secrets = ['stripe_key' => self::SENTINEL]): ExecutionDetailRedactor
    {
        // The REAL rule set, exercised end-to-end through the reader.
        return new ExecutionDetailRedactor(new SecretsProviderStub($secrets), new StepDetailRedactor());
    }

    private function execution(?string $context): WorkflowExecutionStub
    {
        $execution = new WorkflowExecutionStub('uuid-1', 7, 1);
        $execution->setContext($context);

        return $execution;
    }

    /**
     * @param WorkflowExecutionInterface[] $items
     */
    private function reader(
        ?WorkflowExecutionInterface $byId,
        array $items = [],
        int $totalCount = 0,
        ?ExecutionDetailRedactor $redactor = null
    ): WorkflowExecutionReader {
        $searchResults = new class ($items, $totalCount) implements SearchResultsInterface {
            /** @param WorkflowExecutionInterface[] $items */
            public function __construct(private array $items, private readonly int $totalCount)
            {
            }

            public function getItems(): array
            {
                return $this->items;
            }

            public function setItems(array $items): self
            {
                $this->items = $items;

                return $this;
            }

            public function getTotalCount(): int
            {
                return $this->totalCount;
            }
        };

        $repository = new class ($byId, $searchResults) implements WorkflowExecutionRepositoryInterface {
            public function __construct(
                private readonly ?WorkflowExecutionInterface $byId,
                private readonly SearchResultsInterface $searchResults
            ) {
            }

            public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
            {
                return $execution;
            }

            public function getById(int $executionId): WorkflowExecutionInterface
            {
                return $this->byId;
            }

            public function getByUuid(string $uuid): WorkflowExecutionInterface
            {
                return $this->byId;
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                return $this->searchResults;
            }
        };

        return new WorkflowExecutionReader($repository, $redactor ?? $this->redactor());
    }

    public function testSingleExecutionContextIsRedacted(): void
    {
        $context = (string) json_encode([
            'trigger' => ['increment_id' => '000000123'],
            'steps' => ['notify' => ['webhook' => 'https://hooks.test/x?token=' . self::SENTINEL]],
        ]);

        $result = $this->reader($this->execution($context))->getById(7);

        $this->assertFalse(
            str_contains((string) $result->getContext(), self::SENTINEL),
            'A secret value must never reach the single-execution REST response verbatim'
        );
        $this->assertStringContainsString('***', (string) $result->getContext());
        // Everything non-secret survives: this is redaction, not suppression.
        $this->assertStringContainsString('000000123', (string) $result->getContext());
    }

    /**
     * Outside a credential-shaped context the strong layer-1 rule survives, so
     * the operator reading the response can tell WHICH secret was interpolated
     * here — the same behaviour the steps route gives. (Inside a `token=…`
     * context layer 2 fires afterwards and clobbers the name with a bare mask;
     * that is documented in StepDetailRedactorTest and is not a leak.)
     */
    public function testTheMaskNamesTheSecretWhenLayerTwoDoesNotClobberIt(): void
    {
        $context = (string) json_encode(['steps' => ['notify' => ['body' => 'used ' . self::SENTINEL . ' here']]]);

        $result = $this->reader($this->execution($context))->getById(7);

        $this->assertStringContainsString('***stripe_key***', (string) $result->getContext());
        $this->assertFalse(str_contains((string) $result->getContext(), self::SENTINEL));
    }

    /**
     * The generic credential-shape layer catches what is not a registered
     * secret — the same defence-in-depth the steps route gets.
     */
    public function testUnregisteredCredentialShapesAreMaskedToo(): void
    {
        $context = (string) json_encode(['steps' => ['call' => ['auth' => 'Bearer abcdef0123456789']]]);

        $result = $this->reader($this->execution($context), [], 0, $this->redactor([]))->getById(7);

        $this->assertFalse(str_contains((string) $result->getContext(), 'abcdef0123456789'));
    }

    public function testEmptyContextIsLeftAlone(): void
    {
        $this->assertNull($this->reader($this->execution(null))->getById(7)->getContext());
        $this->assertSame('', $this->reader($this->execution(''))->getById(7)->getContext());
    }

    public function testEveryListRowIsRedactedAndTheTotalIsUntouched(): void
    {
        $rows = [
            $this->execution((string) json_encode(['steps' => ['a' => ['url' => 'https://x/' . self::SENTINEL]]])),
            $this->execution((string) json_encode(['steps' => ['b' => ['url' => 'https://y/' . self::SENTINEL]]])),
        ];

        $results = $this->reader(null, $rows, 4321)->getList(
            new class implements SearchCriteriaInterface {
            }
        );

        $this->assertCount(2, $results->getItems());
        foreach ($results->getItems() as $item) {
            $this->assertFalse(
                str_contains((string) $item->getContext(), self::SENTINEL),
                'The list route serializes the same context blob and must redact every row'
            );
        }
        $this->assertSame(
            4321,
            $results->getTotalCount(),
            'Redaction must not touch the honest total the repository reported'
        );
    }
}
