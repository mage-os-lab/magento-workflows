<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Unit\Block\Adminhtml\Canvas;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Api\ActionMetadataProviderInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\SecretMetadataProviderInterface;
use MageOS\Workflows\Api\TriggerMetadataProviderInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\WorkflowsCanvas\Block\Adminhtml\Canvas\Mount;
use PHPUnit\Framework\TestCase;

/**
 * The `approvalsAvailable` bootstrap flag gates the canvas palette's
 * "Approval gate" entry (docs/discovery/approval-gate.md §7: renders only
 * when both optional packages are present). It must key off Module\Manager
 * module presence — NOT a nullable DI seam: the addon's di.xml injects the
 * ApprovalTaskManagerInterface argument explicitly for the core consumers
 * only, so a nullable optional ctor arg here would silently stay null even
 * with the addon installed, and the addon may not wire a canvas class itself
 * (neither optional package depends on the other).
 *
 * Mount extends Backend\Block\Template (an empty shim in the standalone
 * runner) whose real constructor is layout-heavy, so the block is built as an
 * anonymous subclass with a no-op constructor overriding the three inherited
 * helpers getConfigJson() calls, with Mount's own promoted dependencies
 * injected by reflection — the same posture DataProviderTest takes for the
 * UI AbstractDataProvider.
 */
class MountApprovalsAvailableTest extends TestCase
{
    public function testFlagTrueWhenApprovalsModuleEnabled(): void
    {
        $config = $this->configFor($this->mount(['MageOS_WorkflowsApprovals']));

        $this->assertTrue($config['approvalsAvailable']);
    }

    public function testFlagFalseWhenApprovalsModuleAbsent(): void
    {
        $config = $this->configFor($this->mount([]));

        $this->assertFalse($config['approvalsAvailable']);
    }

    public function testFlagKeysOffTheApprovalsModuleSpecifically(): void
    {
        // Some other module being enabled must not light the flag up.
        $config = $this->configFor($this->mount(['MageOS_Workflows', 'MageOS_WorkflowsCanvas']));

        $this->assertFalse($config['approvalsAvailable']);
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(Mount $mount): array
    {
        $config = json_decode($mount->getConfigJson(), true);
        $this->assertTrue(is_array($config), 'getConfigJson must emit a JSON object');
        $this->assertArrayHasKey('approvalsAvailable', $config);
        return $config;
    }

    /**
     * @param string[] $enabledModules module names Module\Manager reports enabled
     */
    private function mount(array $enabledModules): Mount
    {
        $mount = new class extends Mount {
            // Skip the layout-heavy Template constructor entirely.
            public function __construct()
            {
            }

            public function getRequest()
            {
                return new class implements RequestInterface {
                    public function getParam($key, $default = null)
                    {
                        return $default;
                    }

                    public function getModuleName()
                    {
                        return '';
                    }

                    public function setModuleName($name)
                    {
                        return $this;
                    }

                    public function getActionName()
                    {
                        return '';
                    }

                    public function setActionName($name)
                    {
                        return $this;
                    }

                    public function setParams(array $params)
                    {
                        return $this;
                    }

                    public function getParams()
                    {
                        return [];
                    }

                    public function getCookie($name, $default)
                    {
                        return $default;
                    }

                    public function isSecure()
                    {
                        return false;
                    }
                };
            }

            public function getUrl($route = '', $params = [])
            {
                return '/' . ltrim((string) $route, '/');
            }

            public function getFormKey()
            {
                return 'test_form_key';
            }
        };

        $moduleManager = new class($enabledModules) extends ModuleManager {
            /**
             * @param string[] $enabled
             */
            public function __construct(private readonly array $enabled)
            {
                // Deliberately no parent call: the real Manager constructor
                // wants module-list collaborators this test never exercises.
            }

            public function isEnabled($moduleName)
            {
                return in_array($moduleName, $this->enabled, true);
            }
        };

        $repository = new class implements WorkflowRepositoryInterface {
            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                throw new \LogicException('not exercised');
            }

            public function getById(int $workflowId): WorkflowInterface
            {
                throw new NoSuchEntityException(__('no workflow %1', $workflowId));
            }

            public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
            {
                throw new \LogicException('not exercised');
            }

            public function delete(WorkflowInterface $workflow): bool
            {
                throw new \LogicException('not exercised');
            }

            public function deleteById(int $workflowId): bool
            {
                throw new \LogicException('not exercised');
            }
        };

        $dependencies = [
            'workflowRepository' => $repository,
            'actionMetadataProvider' => new class implements ActionMetadataProviderInterface {
                public function getActions(?string $entityType = null): array
                {
                    return [];
                }
            },
            'triggerMetadataProvider' => new class implements TriggerMetadataProviderInterface {
                public function getTriggers(): array
                {
                    return [];
                }
            },
            'secretMetadataProvider' => new class implements SecretMetadataProviderInterface {
                public function getSecretNames(): array
                {
                    return [];
                }
            },
            'authorization' => new class implements AuthorizationInterface {
                public function isAllowed($resource, $privilege = null)
                {
                    return false;
                }
            },
            'moduleManager' => $moduleManager,
        ];
        foreach ($dependencies as $property => $value) {
            $reflection = new \ReflectionProperty(Mount::class, $property);
            $reflection->setValue($mount, $value);
        }

        return $mount;
    }
}
