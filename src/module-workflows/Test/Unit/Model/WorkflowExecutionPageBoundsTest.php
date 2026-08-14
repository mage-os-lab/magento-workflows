<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\Workflows\Model\WorkflowExecutionRepository;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflow-executions pagination bounds.
 *
 * The bug: omitting pageSize meant "no LIMIT", so the route hydrated the entire
 * mageos_workflow_execution table — a table designed for millions of rows, each
 * carrying two MEDIUMTEXT columns (definition_snapshot, context) — into PHP
 * memory, at the weakest grant (::view). One curl was a denial of service.
 *
 * Standing the repository up needs five DI collaborators, two of them Magento
 * generated factories that do not exist outside a built install, so the bound
 * is exercised through the public static seam getList() calls (see
 * WorkflowExecutionRepository::applyPageBounds()).
 */
class WorkflowExecutionPageBoundsTest extends TestCase
{
    /**
     * A criteria double that records what the repository wrote back onto it.
     * The Magento interface is not available in the standalone lane beyond an
     * empty marker, so the two accessors under test are declared here.
     */
    private function criteria(mixed $pageSize): object
    {
        return new class ($pageSize) implements SearchCriteriaInterface {
            public function __construct(private mixed $pageSize)
            {
            }

            public function getPageSize(): mixed
            {
                return $this->pageSize;
            }

            public function setPageSize($pageSize): self
            {
                $this->pageSize = $pageSize;

                return $this;
            }
        };
    }

    public function testAnOmittedPageSizeBecomesTheDefaultRatherThanTheWholeTable(): void
    {
        $criteria = $this->criteria(null);

        WorkflowExecutionRepository::applyPageBounds($criteria);

        $this->assertSame(
            WorkflowExecutionRepository::DEFAULT_PAGE_SIZE,
            $criteria->getPageSize(),
            'A null page size must never mean "unlimited" on this table'
        );
    }

    public function testAnHonestPageSizeIsLeftAlone(): void
    {
        $criteria = $this->criteria(25);

        WorkflowExecutionRepository::applyPageBounds($criteria);

        $this->assertSame(25, $criteria->getPageSize());
    }

    public function testAPageSizeAtTheCapIsLeftAlone(): void
    {
        $criteria = $this->criteria(WorkflowExecutionRepository::MAX_PAGE_SIZE);

        WorkflowExecutionRepository::applyPageBounds($criteria);

        $this->assertSame(WorkflowExecutionRepository::MAX_PAGE_SIZE, $criteria->getPageSize());
    }

    public function testAnOversizedRequestIsClampedNotRejected(): void
    {
        $criteria = $this->criteria(1000000);

        WorkflowExecutionRepository::applyPageBounds($criteria);

        $this->assertSame(
            WorkflowExecutionRepository::MAX_PAGE_SIZE,
            $criteria->getPageSize(),
            'An over-large ask is capped so the caller can still page for the rest'
        );
    }

    /**
     * Zero, negatives and junk all mean "the caller named no page size". None of
     * them may fall through to unlimited — 0 in particular reads as "no limit"
     * to a Magento collection.
     */
    public function testNonsensePageSizesFallBackToTheDefault(): void
    {
        foreach ([0, -1, -1000, '', 'all', 'null', [], null] as $bogus) {
            $criteria = $this->criteria($bogus);

            WorkflowExecutionRepository::applyPageBounds($criteria);

            $this->assertSame(
                WorkflowExecutionRepository::DEFAULT_PAGE_SIZE,
                $criteria->getPageSize(),
                'page size ' . var_export($bogus, true) . ' must fall back to the default'
            );
        }
    }

    public function testNumericStringsAreHonouredAndCapped(): void
    {
        $criteria = $this->criteria('75');
        WorkflowExecutionRepository::applyPageBounds($criteria);
        $this->assertSame(75, $criteria->getPageSize());

        $capped = $this->criteria('99999');
        WorkflowExecutionRepository::applyPageBounds($capped);
        $this->assertSame(WorkflowExecutionRepository::MAX_PAGE_SIZE, $capped->getPageSize());
    }

    /**
     * The numbers themselves are a product decision, not an accident — pin them
     * so a "let's just bump it" change is a deliberate, reviewed edit.
     */
    public function testTheBoundsAreTheDocumentedOnes(): void
    {
        $this->assertSame(50, WorkflowExecutionRepository::DEFAULT_PAGE_SIZE);
        $this->assertSame(200, WorkflowExecutionRepository::MAX_PAGE_SIZE);
        $this->assertTrue(
            WorkflowExecutionRepository::DEFAULT_PAGE_SIZE <= WorkflowExecutionRepository::MAX_PAGE_SIZE,
            'The default must be reachable without asking for it'
        );
    }
}
