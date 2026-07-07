<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * TEMPORARY diagnostics for the fixture-invisibility cluster (docs/20 lane
 * bring-up): core legacy fixtures apply without error yet their entities are
 * not found by the test bodies. This class sorts first in the first module,
 * so it observes the environment before any other suite mutates it. Each
 * assertion message carries the facts needed to pick the fix. Remove once
 * the cluster is resolved.
 */
class AaFixtureProbeTest extends TestCase
{
    public function testEnvironmentFacts(): void
    {
        $facts = ['cwd=' . getcwd()];

        foreach (
            [
                'customer' => 'testsuite/Magento/Customer/_files/customer.php',
                'order' => 'testsuite/Magento/Sales/_files/order.php',
                'product' => 'testsuite/Magento/Catalog/_files/product_simple.php',
            ] as $key => $rel
        ) {
            $facts[] = sprintf('%s_fixture_file=%s', $key, is_file(getcwd() . '/' . $rel) ? 'present' : 'ABSENT');
        }

        $objectManager = Bootstrap::getObjectManager();
        try {
            $facts[] = 'area=' . $objectManager->get(State::class)->getAreaCode();
        } catch (\Throwable $e) {
            $facts[] = 'area=UNSET(' . $e->getMessage() . ')';
        }

        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $facts[] = 'txn_level=' . $connection->getTransactionLevel();
        $facts[] = 'customer_rows=' . $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $connection->getTableName('customer_entity')
        );

        // Deliberate "failure" so the facts land in the CI log verbatim.
        $this->assertSame('__facts__', implode(' | ', $facts));
    }

    /**
     * @magentoDbIsolation enabled
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testCustomerFixtureIsVisibleToTheTestBody(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();

        $rawCount = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $connection->getTableName('customer_entity')
            . ' WHERE email = ?',
            ['customer@example.com']
        );

        $repoFound = 'no';
        try {
            $objectManager->get(CustomerRepositoryInterface::class)->get('customer@example.com', 1);
            $repoFound = 'yes';
        } catch (\Throwable $e) {
            $repoFound = 'no(' . get_class($e) . ')';
        }

        $this->assertSame(
            1,
            $rawCount,
            sprintf(
                'raw customer_entity row for the fixture: %d found; repository lookup: %s; txn_level=%d',
                $rawCount,
                $repoFound,
                $connection->getTransactionLevel()
            )
        );
        $this->assertSame('yes', $repoFound, 'raw row exists but the repository lookup failed');
    }
}
