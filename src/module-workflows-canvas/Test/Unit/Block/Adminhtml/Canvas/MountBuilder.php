<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Unit\Block\Adminhtml\Canvas;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Api\ActionMetadataProviderInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\SecretMetadataProviderInterface;
use MageOS\Workflows\Api\TriggerMetadataProviderInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\WorkflowsCanvas\Block\Adminhtml\Canvas\Mount;
use MageOS\WorkflowsCanvas\Model\I18n\PhraseCatalog;

/**
 * Shared construction for the Mount block's unit tests (NOT a *Test.php, so the
 * standalone runner does not try to execute it).
 *
 * Mount extends Backend\Block\Template (an empty shim in the standalone runner)
 * whose real constructor is layout-heavy, so the block is built as an anonymous
 * subclass with a no-op constructor overriding the three inherited helpers
 * getConfigJson() calls, with Mount's own promoted dependencies injected by
 * reflection — the same posture DataProviderTest takes for the UI
 * AbstractDataProvider.
 */
final class MountBuilder
{
    /** @var string[] */
    private array $enabledModules = [];

    /** @var array<string, bool> */
    private array $grants = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $options = [
        'entityTypeSource' => [],
        'triggerTypeSource' => [],
        'statusSource' => [],
        'websiteSource' => [],
    ];

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param string[] $modules module names Module\Manager reports enabled
     */
    public function withEnabledModules(array $modules): self
    {
        $this->enabledModules = $modules;
        return $this;
    }

    /**
     * @param array<string, bool> $grants ACL resource => isAllowed
     */
    public function withGrants(array $grants): self
    {
        $this->grants = $grants;
        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $rows raw toOptionArray() rows
     */
    public function withOptionSource(string $constructorArgument, array $rows): self
    {
        $this->options[$constructorArgument] = $rows;
        return $this;
    }

    public function build(): Mount
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

        $dependencies = [
            'workflowRepository' => $this->repository(),
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
            'authorization' => $this->authorization(),
            'moduleManager' => $this->moduleManager(),
            // The real catalog: it is pure __() lookups, so the tests exercise
            // the exact phrase map production ships.
            'phraseCatalog' => new PhraseCatalog(),
        ];
        foreach ($this->options as $argument => $rows) {
            $dependencies[$argument] = $this->optionSource($rows);
        }

        foreach ($dependencies as $property => $value) {
            $reflection = new \ReflectionProperty(Mount::class, $property);
            $reflection->setValue($mount, $value);
        }

        return $mount;
    }

    private function moduleManager(): ModuleManager
    {
        return new class ($this->enabledModules) extends ModuleManager {
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
    }

    private function authorization(): AuthorizationInterface
    {
        return new class ($this->grants) implements AuthorizationInterface {
            /**
             * @param array<string, bool> $grants
             */
            public function __construct(private readonly array $grants)
            {
            }

            public function isAllowed($resource, $privilege = null)
            {
                return $this->grants[$resource] ?? false;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function optionSource(array $rows): OptionSourceInterface
    {
        return new class ($rows) implements OptionSourceInterface {
            /**
             * @param array<int, array<string, mixed>> $rows
             */
            public function __construct(private readonly array $rows)
            {
            }

            public function toOptionArray()
            {
                return $this->rows;
            }
        };
    }

    private function repository(): WorkflowRepositoryInterface
    {
        return new class implements WorkflowRepositoryInterface {
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
    }
}
