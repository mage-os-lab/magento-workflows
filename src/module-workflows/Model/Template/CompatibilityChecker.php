<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;

/**
 * Evaluates a template's `requires` clause against this install (discovery §2):
 * every trigger declared in TriggerRegistry, every action in ActionPool,
 * `schema` ≤ the engine's SCHEMA_VERSION, module presence, and edition. Each
 * shortfall is a typed CompatibilityReason powering a greyed-out card ("requires
 * the B2B pack") — which doubles as ecosystem marketing for connector packages.
 *
 * The default-locale check rides here too: "a localized title/description must
 * carry the install locale or the canonical default" is runtime knowledge JSON
 * Schema cannot express, so it is a compat reason, not a schema constraint.
 */
class CompatibilityChecker
{
    /**
     * Editions that satisfy each `requires.edition` value. 'any'/'community'
     * are always satisfied; the Commerce editions map to Magento's own
     * getEdition() strings, and 'b2b' additionally accepts the B2B module.
     */
    private const B2B_MODULE = 'Magento_B2b';

    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly TriggerRegistry $triggerRegistry,
        private readonly ModuleManager $moduleManager,
        private readonly ProductMetadataInterface $productMetadata
    ) {
    }

    /**
     * @param string $locale the install locale the card will render in
     */
    public function check(TemplateSummary $summary, string $locale = LocalizedText::DEFAULT_LOCALE): CompatibilityResult
    {
        $reasons = [];
        $requires = $summary->getRequires();

        $schema = $requires['schema'] ?? null;
        if (is_int($schema) && $schema > Definition::SCHEMA_VERSION) {
            $reasons[] = new CompatibilityReason(
                CompatibilityReason::SCHEMA_TOO_NEW,
                __(
                    'Requires workflow schema %1, but this engine supports up to %2. Update the module.',
                    $schema,
                    Definition::SCHEMA_VERSION
                )
            );
        }

        foreach ($this->stringList($requires['triggers'] ?? null) as $trigger) {
            if ($this->triggerRegistry->getByEvent($trigger) === null) {
                $reasons[] = new CompatibilityReason(
                    CompatibilityReason::MISSING_TRIGGER,
                    __('Requires the "%1" trigger, which is not available on this install.', $trigger)
                );
            }
        }

        foreach ($this->stringList($requires['actions'] ?? null) as $action) {
            if (!$this->actionPool->has($action)) {
                $reasons[] = new CompatibilityReason(
                    CompatibilityReason::MISSING_ACTION,
                    __('Requires the "%1" action, which is not installed. It may ship in a connector pack.', $action)
                );
            }
        }

        foreach ($this->stringList($requires['modules'] ?? null) as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                $reasons[] = new CompatibilityReason(
                    CompatibilityReason::MISSING_MODULE,
                    __('Requires the "%1" module, which is not enabled.', $module)
                );
            }
        }

        $editionReason = $this->checkEdition((string) ($requires['edition'] ?? 'any'));
        if ($editionReason !== null) {
            $reasons[] = $editionReason;
        }

        if (!LocalizedText::isResolvable($summary->getTitleData(), $locale)
            || !LocalizedText::isResolvable($summary->getDescriptionData(), $locale)) {
            $reasons[] = new CompatibilityReason(
                CompatibilityReason::MISSING_LOCALE,
                __(
                    'This template has no copy for the "%1" locale or the default "%2".',
                    $locale,
                    LocalizedText::DEFAULT_LOCALE
                )
            );
        }

        return new CompatibilityResult($reasons);
    }

    private function checkEdition(string $edition): ?CompatibilityReason
    {
        $edition = $edition === '' ? 'any' : $edition;
        if ($edition === 'any' || $edition === 'community') {
            return null;
        }

        $installEdition = strtolower((string) $this->productMetadata->getEdition());
        $isCommerce = $installEdition !== '' && $installEdition !== 'community';

        if ($edition === 'enterprise') {
            return $isCommerce
                ? null
                : new CompatibilityReason(
                    CompatibilityReason::EDITION_MISMATCH,
                    __('Requires Adobe Commerce; this is a Community/Mage-OS install.')
                );
        }

        if ($edition === 'b2b') {
            return ($isCommerce && $this->moduleManager->isEnabled(self::B2B_MODULE))
                ? null
                : new CompatibilityReason(
                    CompatibilityReason::EDITION_MISMATCH,
                    __('Requires the Adobe Commerce B2B module, which is not enabled.')
                );
        }

        // Unknown edition token: treat as unsatisfiable rather than silently ok.
        return new CompatibilityReason(
            CompatibilityReason::EDITION_MISMATCH,
            __('Requires an unrecognized edition "%1".', $edition)
        );
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? $v : '', $value),
            static fn (string $v): bool => $v !== ''
        ));
    }
}
