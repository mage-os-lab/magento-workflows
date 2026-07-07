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
     * Measures the ANNOTATION-PARSING layer at runtime on the real installed
     * framework: if the parsed sets below come back empty for a method that
     * plainly carries the annotation, the whole fixture pipeline silently
     * no-ops — which matches every observation so far (no errors, no rows,
     * both core and module-scoped fixtures invisible).
     *
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testAnnotationParsingLayer(): void
    {
        $report = [];
        try {
            $annotations = \Magento\TestFramework\Annotation\TestCaseAnnotation::getInstance()
                ->getAnnotations($this);
            $report[] = 'method_fixtures=' . json_encode(
                $annotations['method']['magentoDataFixture'] ?? 'KEY-ABSENT'
            );
            $report[] = 'class_keys=' . json_encode(array_keys((array)($annotations['class'] ?? [])));
            $report[] = 'method_keys=' . json_encode(array_keys((array)($annotations['method'] ?? [])));
        } catch (\Throwable $e) {
            $report[] = 'annotation_service_error=' . get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertSame('__annotations__', implode(' | ', $report));
    }

    /**
     * Applies the SAME legacy fixture in-body through the framework's own
     * resolver, bypassing the annotation machinery entirely: if this works,
     * the annotation layer is dropping fixtures; if it throws, the message
     * below is the real reason the entities never materialize.
     */
    public function testDirectLegacyFixtureApplication(): void
    {
        $error = 'none';
        try {
            $resolver = \Magento\TestFramework\Workaround\Override\Fixture\Resolver::getInstance();
            $resolver->setCurrentFixtureType(\Magento\TestFramework\Annotation\DataFixture::ANNOTATION);
            $resolver->requireDataFixture('Magento/Customer/_files/customer.php');
            $resolver->setCurrentFixtureType(null);
        } catch (\Throwable $e) {
            $error = sprintf(
                '%s: %s @ %s:%d',
                get_class($e),
                $e->getMessage(),
                basename((string)$e->getFile()),
                $e->getLine()
            );
        }

        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $rows = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $connection->getTableName('customer_entity')
            . ' WHERE email = ?',
            ['customer@example.com']
        );

        $this->assertSame(
            1,
            $rows,
            sprintf('direct requireDataFixture: rows=%d, error=%s', $rows, $error)
        );
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
