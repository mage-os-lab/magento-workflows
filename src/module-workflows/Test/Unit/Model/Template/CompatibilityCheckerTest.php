<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Template;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\CompatibilityReason;
use MageOS\Workflows\Model\Template\TemplateSummary;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use PHPUnit\Framework\TestCase;

/**
 * The compat matrix (06 stage 2 done-when): missing trigger / action / module /
 * edition / schema-too-new each yields its own typed reason; a fully satisfied
 * `requires` is compatible.
 */
class CompatibilityCheckerTest extends TestCase
{
    private function checker(
        array $triggers = ['sales.order.created'],
        array $actions = ['notify.email'],
        array $enabledModules = [],
        string $edition = 'Community'
    ): CompatibilityChecker {
        $registry = new class ($triggers) extends TriggerRegistry {
            /** @param string[] $available */
            public function __construct(private readonly array $available)
            {
            }

            public function getByEvent(string $event): ?array
            {
                return in_array($event, $this->available, true)
                    ? ['event' => $event, 'entity' => 'sales_order', 'label' => $event]
                    : null;
            }
        };

        $pool = new ActionPool(array_combine(
            $actions,
            array_map(static fn (string $code): StubAction => new StubAction($code, $code), $actions)
        ));

        $moduleManager = new class ($enabledModules) extends ModuleManager {
            /** @param string[] $enabled */
            public function __construct(private readonly array $enabled)
            {
            }

            public function isEnabled($moduleName)
            {
                return in_array($moduleName, $this->enabled, true);
            }
        };

        $metadata = new class ($edition) implements ProductMetadataInterface {
            public function __construct(private readonly string $edition)
            {
            }

            public function getEdition()
            {
                return $this->edition;
            }

            public function getVersion()
            {
                return '2.4.7';
            }

            public function getName()
            {
                return 'Mage-OS';
            }
        };

        return new CompatibilityChecker($pool, $registry, $moduleManager, $metadata);
    }

    private function summary(array $requires): TemplateSummary
    {
        return new TemplateSummary('t', 'Title', 'Desc', 'Cat', '1.0.0', $requires, []);
    }

    public function testFullySatisfiedIsCompatible(): void
    {
        $result = $this->checker()->check($this->summary([
            'schema' => 2,
            'triggers' => ['sales.order.created'],
            'actions' => ['notify.email'],
            'edition' => 'any',
        ]));
        $this->assertTrue($result->isCompatible());
        $this->assertSame([], $result->getReasons());
    }

    public function testMissingTrigger(): void
    {
        $result = $this->checker()->check($this->summary(['triggers' => ['quote.abandoned']]));
        $this->assertFalse($result->isCompatible());
        $this->assertTrue($result->hasReasonWithCode(CompatibilityReason::MISSING_TRIGGER));
    }

    public function testMissingAction(): void
    {
        $result = $this->checker()->check($this->summary(['actions' => ['marketing.generate_coupon']]));
        $this->assertTrue($result->hasReasonWithCode(CompatibilityReason::MISSING_ACTION));
    }

    public function testMissingModule(): void
    {
        $result = $this->checker()->check($this->summary(['modules' => ['Vendor_Connector']]));
        $this->assertTrue($result->hasReasonWithCode(CompatibilityReason::MISSING_MODULE));
    }

    public function testSchemaTooNew(): void
    {
        $result = $this->checker()->check($this->summary(['schema' => Definition::SCHEMA_VERSION + 1]));
        $this->assertTrue($result->hasReasonWithCode(CompatibilityReason::SCHEMA_TOO_NEW));
    }

    public function testEnterpriseRequiredOnCommunityFails(): void
    {
        $result = $this->checker(edition: 'Community')->check($this->summary(['edition' => 'enterprise']));
        $this->assertTrue($result->hasReasonWithCode(CompatibilityReason::EDITION_MISMATCH));
    }

    public function testEnterpriseSatisfiedOnCommerce(): void
    {
        $result = $this->checker(edition: 'Enterprise')->check($this->summary(['edition' => 'enterprise']));
        $this->assertTrue($result->isCompatible());
    }

    public function testB2bRequiresModuleAndEdition(): void
    {
        $withoutModule = $this->checker(edition: 'Enterprise')->check($this->summary(['edition' => 'b2b']));
        $this->assertTrue($withoutModule->hasReasonWithCode(CompatibilityReason::EDITION_MISMATCH));

        $withModule = $this->checker(edition: 'Enterprise', enabledModules: ['Magento_B2b'])
            ->check($this->summary(['edition' => 'b2b']));
        $this->assertTrue($withModule->isCompatible());
    }

    public function testMissingDefaultLocaleReason(): void
    {
        $summary = new TemplateSummary(
            't',
            ['de_DE' => 'Hallo'],
            ['de_DE' => 'Beschreibung'],
            'Cat',
            '1.0.0',
            [],
            []
        );
        $result = $this->checker()->check($summary, 'en_US');
        $this->assertTrue($result->hasReasonWithCode(CompatibilityReason::MISSING_LOCALE));
    }
}
