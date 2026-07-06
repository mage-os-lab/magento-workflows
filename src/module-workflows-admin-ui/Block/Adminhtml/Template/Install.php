<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Template;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\Install as InstallController;

/**
 * The install parameter form (06). Fields render from the recorded F6
 * option-source union: a bounded source ships inline `options` (a select); a
 * large/search source (`options_search` or an `entity:*` type) renders a plain
 * text input with a note in v1 — the canvas package upgrades it to a picker
 * later. "Install as shadow" is default-on; the gallery never enables.
 */
class Install extends AbstractDetail
{
    /** Field-widget kinds the template chooses between. */
    private const WIDGET_SELECT = 'select';
    private const WIDGET_SECRET = 'secret';
    private const WIDGET_SEARCH = 'search';
    private const WIDGET_TEXT = 'text';

    public function __construct(
        Context $context,
        TemplateSourceInterface $templateSource,
        CompatibilityChecker $compatibilityChecker,
        ResolverInterface $localeResolver,
        private readonly DataPersistorInterface $dataPersistor,
        array $data = []
    ) {
        parent::__construct($context, $templateSource, $compatibilityChecker, $localeResolver, $data);
    }

    public function getTitle(): string
    {
        return (string) $this->getSummary()?->getTitle($this->getLocale());
    }

    public function getFormActionUrl(): string
    {
        return $this->getUrl('mageos_workflows/template/install', ['code' => $this->getCode()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mageos_workflows/template/view', ['code' => $this->getCode()]);
    }

    public function isShadowChecked(): bool
    {
        $persisted = $this->persisted();
        if (array_key_exists('install_shadow', $persisted)) {
            return !empty($persisted['install_shadow']);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $parameter
     */
    public function widget(array $parameter): string
    {
        $options = $parameter['options'] ?? null;
        if (is_array($options) && $options !== []) {
            return self::WIDGET_SELECT;
        }
        if (($parameter['type'] ?? '') === 'secret') {
            return self::WIDGET_SECRET;
        }
        if (isset($parameter['options_search']) || str_starts_with((string) ($parameter['type'] ?? ''), 'entity:')) {
            return self::WIDGET_SEARCH;
        }
        return self::WIDGET_TEXT;
    }

    /**
     * @param array<string, mixed> $parameter
     * @return array<int, array{value: string, label: string}>
     */
    public function options(array $parameter): array
    {
        $options = $parameter['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['value'])) {
                $out[] = ['value' => (string) $option['value'], 'label' => (string) ($option['label'] ?? $option['value'])];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $parameter
     */
    public function fieldLabel(array $parameter): string
    {
        $label = LocalizedText::resolve($parameter['label'] ?? '', $this->getLocale());
        return $label !== '' ? $label : (string) ($parameter['key'] ?? '');
    }

    /**
     * @param array<string, mixed> $parameter
     */
    public function fieldNote(array $parameter): ?Phrase
    {
        return match ($this->widget($parameter)) {
            self::WIDGET_SEARCH => __('Enter the record ID. A searchable picker arrives with the canvas package.'),
            self::WIDGET_SECRET => __('Names the secret. Enter a value to create it, or leave blank to reference an existing secret.'),
            self::WIDGET_TEXT => ($parameter['type'] ?? '') === 'duration'
                ? __('ISO-8601 duration, e.g. PT4H (4 hours) or P1D (1 day).')
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $parameter
     */
    public function isRequired(array $parameter): bool
    {
        return !empty($parameter['required']);
    }

    /**
     * Restored value for a parameter after a failed install, else its default.
     *
     * @param array<string, mixed> $parameter
     */
    public function fieldValue(array $parameter): string
    {
        $key = (string) ($parameter['key'] ?? '');
        $persistedParams = $this->persisted()['param'] ?? [];
        if (is_array($persistedParams) && array_key_exists($key, $persistedParams)) {
            return (string) $persistedParams[$key];
        }
        return isset($parameter['default']) ? (string) $parameter['default'] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function persisted(): array
    {
        $data = $this->dataPersistor->get(InstallController::PERSISTOR_KEY);
        return is_array($data) ? $data : [];
    }
}
