<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Model\Workflow;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;
use MageOS\WorkflowsAdminUi\Model\Workflow\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DataProvider seeds the new-workflow form's entity_type from a validated
 * `entity_type` request param (the "create from the <entity> grid" deep link).
 *
 * DataProvider extends the Magento UI AbstractDataProvider (a shim in the
 * standalone runner) and its constructor pulls a generated CollectionFactory,
 * so instances are built via reflection -- newInstanceWithoutConstructor plus
 * direct property injection -- and getData()/getMeta() are exercised as the
 * DB-free assembly logic they are, with in-memory collection/request/persistor
 * fakes and a mocked metadata provider.
 */
class DataProviderTest extends TestCase
{
    /**
     * @param array<int, object> $items
     * @param array<string, mixed> $requestParams
     * @param array<string, mixed>|null $persisted
     */
    private function dataProvider(array $items, array $requestParams, ?array $persisted = null): DataProvider
    {
        $dataProvider = (new \ReflectionClass(DataProvider::class))->newInstanceWithoutConstructor();

        $collection = new class($items) {
            /**
             * @param array<int, object> $items
             */
            public function __construct(private array $items)
            {
            }

            /**
             * @return array<int, object>
             */
            public function getItems(): array
            {
                return $this->items;
            }
        };

        $request = new class($requestParams) implements RequestInterface {
            /**
             * @param array<string, mixed> $params
             */
            public function __construct(private array $params)
            {
            }

            public function getParam($key, $default = null)
            {
                return $this->params[$key] ?? $default;
            }

            public function getModuleName()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function setModuleName($name)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getActionName()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function setActionName($name)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function setParams($params)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getParams()
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function getCookie($name, $default)
            {
                throw new \BadMethodCallException(__METHOD__);
            }

            public function isSecure()
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };

        $persistor = new class($persisted) implements DataPersistorInterface {
            private bool $cleared = false;

            /**
             * @param array<string, mixed>|null $value
             */
            public function __construct(private ?array $value)
            {
            }

            public function set($key, $value)
            {
            }

            public function get($key)
            {
                return $this->cleared ? null : $this->value;
            }

            public function clear($key)
            {
                $this->cleared = true;
            }
        };

        $metadataProvider = new class implements EntityTypeMetadataProviderInterface {
            public function getEntityTypes(): array
            {
                return [
                    new EntityTypeMetadata('sales_order', 'Order'),
                    new EntityTypeMetadata('customer', 'Customer'),
                ];
            }
        };

        $this->inject($dataProvider, 'collection', $collection);
        $this->inject($dataProvider, 'requestFieldName', 'workflow_id');
        $this->inject($dataProvider, 'dataPersistor', $persistor);
        $this->inject($dataProvider, 'request', $request);
        $this->inject($dataProvider, 'metadataProvider', $metadataProvider);

        return $dataProvider;
    }

    private function inject(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($object);
        while (!$reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();
        }
        $reflection->getProperty($property)->setValue($object, $value);
    }

    private function existingWorkflowModel(): object
    {
        return new class {
            public function getId(): int
            {
                return 7;
            }

            /**
             * @return array<string, mixed>
             */
            public function getData(): array
            {
                return ['workflow_id' => 7, 'entity_type' => 'customer', 'name' => 'Existing'];
            }

            /**
             * @return int[]
             */
            public function getWebsiteIds(): array
            {
                return [];
            }
        };
    }

    public function testSeedsEntityTypeForValidParamOnNewForm(): void
    {
        $data = $this->dataProvider([], ['entity_type' => 'sales_order'])->getData();

        $this->assertSame('sales_order', $data['']['entity_type']);
    }

    public function testIgnoresUnknownEntityTypeParam(): void
    {
        $data = $this->dataProvider([], ['entity_type' => 'not_a_real_type'])->getData();

        $this->assertSame([], $data);
    }

    public function testDoesNotSeedWhenEditingExistingWorkflow(): void
    {
        $data = $this->dataProvider(
            [$this->existingWorkflowModel()],
            ['workflow_id' => '7', 'entity_type' => 'sales_order']
        )->getData();

        // Existing row untouched, no synthetic new-record entry created.
        $this->assertSame('customer', $data[7]['entity_type']);
        $this->assertFalse(array_key_exists('', $data));
    }

    public function testPersistedDataWinsOverSeed(): void
    {
        // Persisted payload has no workflow_id => a new record: it must override
        // the entity_type seeded from the request param.
        $data = $this->dataProvider(
            [],
            ['entity_type' => 'sales_order'],
            ['name' => 'Draft', 'entity_type' => 'customer']
        )->getData();

        $this->assertSame('customer', $data['']['entity_type']);
        $this->assertSame('Draft', $data['']['name']);
    }
}
