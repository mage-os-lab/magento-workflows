<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Template;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Phrase;
use MageOS\Workflows\Model\Option\EntityOptionSourceRegistry;
use MageOS\Workflows\Model\Option\OptionSourcePool;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\Install as InstallController;

/**
 * The install parameter form (06). Every parameter renders through the widget
 * its declared type and the recorded F6 option-source union pick out:
 *
 *  - inline `options` → a select (highest precedence, no source involved);
 *  - `secret` → the name + write-only value pair;
 *  - `duration` → a composite amount/unit control over the real ISO input;
 *  - `number` / `url` → the typed input, with `min`/`max`/`step` echoed as
 *    HTML attributes (the server re-validates all three paths regardless);
 *  - `options_search` or an `entity:*` type → a source-backed widget: a
 *    BOUNDED entity renders as a plain select filled server-side with the
 *    source's FULL list ({@see OptionSourcePool}, uncapped) — unless that list
 *    tops MAX_BOUNDED_SELECT, in which case the field degrades to the same
 *    type-ahead picker every search-typed source gets, fed by
 *    mageos_workflows/data/options;
 *  - everything else → text.
 *
 * A source that is unregistered, uninstalled, or throwing degrades the field to
 * a plain text input rather than failing the form: the operator can always type
 * the record ID, and the install path validates the value server-side either
 * way. "Install as shadow" is default-on; the gallery never enables.
 */
class Install extends AbstractDetail
{
    /** Field-widget kinds the template chooses between. */
    private const WIDGET_SELECT = 'select';
    private const WIDGET_SECRET = 'secret';
    private const WIDGET_SEARCH = 'search';
    private const WIDGET_DURATION = 'duration';
    private const WIDGET_NUMBER = 'number';
    private const WIDGET_URL = 'url';
    private const WIDGET_TEXT = 'text';

    /** Prefix marking a parameter type that names an entity alias. */
    private const ENTITY_TYPE_PREFIX = 'entity:';

    /** Schema default for `options_search.min_chars`. */
    private const DEFAULT_MIN_CHARS = 2;

    /**
     * Most rows a server-rendered select will hold. A "bounded" source larger
     * than this (a store with hundreds of customer groups, a third-party alias
     * on a big catalogue) renders as the search picker instead — never as a
     * truncated or unusably long select.
     */
    private const MAX_BOUNDED_SELECT = 200;

    /**
     * Resolved source per parameter, memoized: widget(), options() and the
     * phtml helpers all consult it, and a bounded source is FETCHED during
     * resolution. Keyed by the encoded parameter (a parameter without a `key`
     * must not collide with another one).
     *
     * @var array<string, array{code: string, bounded: bool, min_chars: int,
     *      options: array<int, array{value: string, label: string}>}|null>
     */
    private array $sourceCache = [];

    public function __construct(
        Context $context,
        TemplateSourceInterface $templateSource,
        CompatibilityChecker $compatibilityChecker,
        ResolverInterface $localeResolver,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly OptionSourcePool $optionSourcePool,
        private readonly EntityOptionSourceRegistry $entityRegistry,
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

        $type = (string) ($parameter['type'] ?? '');
        if ($type === 'secret') {
            return self::WIDGET_SECRET;
        }
        if ($type === 'duration') {
            return self::WIDGET_DURATION;
        }
        if ($type === 'number') {
            return self::WIDGET_NUMBER;
        }
        if ($type === 'url') {
            return self::WIDGET_URL;
        }

        $source = $this->source($parameter);
        if ($source === null) {
            return self::WIDGET_TEXT;
        }
        return $source['bounded'] ? self::WIDGET_SELECT : self::WIDGET_SEARCH;
    }

    /**
     * Select options: the inline set when the parameter declares one, else the
     * bounded source's list, with an empty first entry while nothing is chosen
     * so the form never pre-selects a record the operator never picked.
     *
     * @param array<string, mixed> $parameter
     * @return array<int, array{value: string, label: string}>
     */
    public function options(array $parameter): array
    {
        $inline = $parameter['options'] ?? [];
        if (is_array($inline) && $inline !== []) {
            return $this->normalizeOptions($inline);
        }

        $source = $this->source($parameter);
        if ($source === null || !$source['bounded']) {
            return [];
        }

        $options = $source['options'];
        if ($this->fieldValue($parameter) === '') {
            array_unshift($options, ['value' => '', 'label' => (string) __('-- Please select --')]);
        }
        return $options;
    }

    /**
     * Client config for a search widget: the same-origin admin options feed,
     * the source code to query, and how many characters to wait for.
     *
     * @param array<string, mixed> $parameter
     * @return array{url: string, source: string, min_chars: int}
     */
    public function searchConfig(array $parameter): array
    {
        $source = $this->source($parameter);
        return [
            'url' => $this->getUrl('mageos_workflows/data/options'),
            'source' => $source === null ? '' : $source['code'],
            'min_chars' => $source === null ? self::DEFAULT_MIN_CHARS : $source['min_chars'],
        ];
    }

    /**
     * "Label (ID)" for a search widget's current value, so a restored or
     * defaulted record reads as a name rather than a bare ID. '' when there is
     * no value, no source, or the source does not know the value.
     *
     * @param array<string, mixed> $parameter
     */
    public function currentValueLabel(array $parameter): string
    {
        $value = $this->fieldValue($parameter);
        $source = $this->source($parameter);
        if ($value === '' || $source === null) {
            return '';
        }

        try {
            foreach ($this->optionSourcePool->get($source['code'])->fetch($value) as $option) {
                if ((string) ($option['value'] ?? '') === $value) {
                    return sprintf('%s (%s)', (string) ($option['label'] ?? $value), $value);
                }
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /**
     * The current duration value split into an amount and a unit the composite
     * control can render. Null for anything the composite cannot represent
     * exactly (no value, a compound interval such as P1DT12H, garbage) — the
     * field then stays a raw ISO input, the same fallback JavaScript-off gets.
     *
     * @param array<string, mixed> $parameter
     * @return array{value: int, unit: string}|null
     */
    public function durationParts(array $parameter): ?array
    {
        $value = $this->fieldValue($parameter);
        $patterns = [
            '/^P(\d+)D$/' => 'days',
            '/^PT(\d+)H$/' => 'hours',
            '/^PT(\d+)M$/' => 'minutes',
        ];
        foreach ($patterns as $pattern => $unit) {
            if (preg_match($pattern, $value, $matches) === 1) {
                return ['value' => (int) $matches[1], 'unit' => $unit];
            }
        }
        return null;
    }

    /**
     * The `min`/`max`/`step` the parameter declares, as HTML attribute values.
     * `min`/`max` are advisory here — every install path re-checks them in
     * ParameterEngine; `step` is client-only by contract.
     *
     * @param array<string, mixed> $parameter
     * @return array<string, string> attribute => value
     */
    public function numberAttrs(array $parameter): array
    {
        $attrs = [];
        foreach (['min', 'max', 'step'] as $attribute) {
            if (isset($parameter[$attribute]) && is_scalar($parameter[$attribute])) {
                $attrs[$attribute] = (string) $parameter[$attribute];
            }
        }
        return $attrs;
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
     * The authored `note` wins over the widget-derived one: a template author
     * who explains the field knows more about it than the widget mapping does.
     *
     * @param array<string, mixed> $parameter
     */
    public function fieldNote(array $parameter): ?Phrase
    {
        $authored = LocalizedText::resolve($parameter['note'] ?? '', $this->getLocale());
        if ($authored !== '') {
            return new Phrase($authored);
        }

        return match ($this->widget($parameter)) {
            self::WIDGET_SEARCH => __('Search by name, or enter the record ID.'),
            self::WIDGET_SECRET => __('Names the secret. Enter a value to create it, or leave blank to reference an existing secret.'),
            // Shown in raw ISO mode and with JavaScript off, where the field is
            // the plain interval input the composite control replaces.
            self::WIDGET_DURATION => __('ISO-8601 duration, e.g. PT4H (4 hours) or P1D (1 day).'),
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
     * @param array<string, mixed> $parameter
     * @return array{code: string, bounded: bool, min_chars: int,
     *         options: array<int, array{value: string, label: string}>}|null
     */
    private function source(array $parameter): ?array
    {
        $cacheKey = (string) json_encode($parameter);
        if (!array_key_exists($cacheKey, $this->sourceCache)) {
            $this->sourceCache[$cacheKey] = $this->resolveSource($parameter);
        }
        return $this->sourceCache[$cacheKey];
    }

    /**
     * Resolve the option source behind a parameter, following the recorded
     * precedence (`options_search` over an `entity:*` mapping; inline `options`
     * never reach here). Null means "no usable source": the caller degrades to
     * a text input. A bounded source is fetched here rather than in options()
     * so a source that throws takes the SAME null path — the field falls back
     * to text instead of rendering an empty select or 500ing the form.
     *
     * @param array<string, mixed> $parameter
     * @return array{code: string, bounded: bool, min_chars: int,
     *         options: array<int, array{value: string, label: string}>}|null
     */
    private function resolveSource(array $parameter): ?array
    {
        try {
            $search = $parameter['options_search'] ?? null;
            if (is_array($search) && is_string($search['source'] ?? null) && $search['source'] !== '') {
                if (!$this->optionSourcePool->has($search['source'])) {
                    return null;
                }
                return [
                    'code' => (string) $search['source'],
                    'bounded' => false,
                    'min_chars' => (int) ($search['min_chars'] ?? self::DEFAULT_MIN_CHARS),
                    'options' => [],
                ];
            }

            $type = (string) ($parameter['type'] ?? '');
            if (!str_starts_with($type, self::ENTITY_TYPE_PREFIX)) {
                return null;
            }
            $alias = substr($type, strlen(self::ENTITY_TYPE_PREFIX));
            if (!$this->entityRegistry->has($alias)) {
                return null;
            }
            $code = $this->entityRegistry->sourceCode($alias);
            if (!$this->optionSourcePool->has($code)) {
                return null;
            }

            $bounded = $this->entityRegistry->isBounded($alias);
            $options = $bounded
                ? $this->normalizeOptions($this->optionSourcePool->get($code)->all())
                : [];
            if (count($options) > self::MAX_BOUNDED_SELECT) {
                // "Bounded" turned out not to be: degrade to the search picker
                // (min_chars stays 0, so it searches from the first keystroke)
                // rather than render a truncated or unusably long select.
                $bounded = false;
                $options = [];
            }
            return [
                'code' => $code,
                'bounded' => $bounded,
                'min_chars' => $this->entityRegistry->minChars($alias),
                'options' => $options,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<int, mixed> $options
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeOptions(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['value'])) {
                $out[] = ['value' => (string) $option['value'], 'label' => (string) ($option['label'] ?? $option['value'])];
            }
        }
        return $out;
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
