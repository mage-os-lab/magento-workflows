<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Template;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Locale\ResolverInterface;
use MageOS\Workflows\Model\PlainLanguageRenderer;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\CompatibilityResult;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\ParameterEngine;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;

/**
 * Template detail (06): the plain-language rendering of the workflow with its
 * default parameter values substituted (reusing the core PlainLanguageRenderer),
 * plus the `requires` panel and parameter list.
 */
class View extends AbstractDetail
{
    public function __construct(
        Context $context,
        TemplateSourceInterface $templateSource,
        CompatibilityChecker $compatibilityChecker,
        ResolverInterface $localeResolver,
        private readonly ParameterEngine $parameterEngine,
        private readonly PlainLanguageRenderer $plainLanguageRenderer,
        array $data = []
    ) {
        parent::__construct($context, $templateSource, $compatibilityChecker, $localeResolver, $data);
    }

    public function getTitle(): string
    {
        $summary = $this->getSummary();
        return $summary === null ? '' : $summary->getTitle($this->getLocale());
    }

    public function getDescription(): string
    {
        $summary = $this->getSummary();
        return $summary === null ? '' : $summary->getDescription($this->getLocale());
    }

    public function getCategory(): string
    {
        return (string) $this->getSummary()?->getCategory();
    }

    public function getVersion(): string
    {
        return (string) $this->getSummary()?->getVersion();
    }

    public function getCompatibility(): CompatibilityResult
    {
        $summary = $this->getSummary();
        return $summary === null
            ? new CompatibilityResult([])
            : $this->compatibilityChecker->check($summary, $this->getLocale());
    }

    /**
     * Plain-language sentence for the workflow with defaults substituted.
     * Best-effort: a substitution failure (e.g. a required param with no
     * default) falls back to the raw body rather than blanking the preview.
     */
    public function getPlainLanguage(): string
    {
        $workflow = $this->getWorkflowNode();
        if ($workflow === null) {
            return '';
        }

        $values = $this->previewValues();
        try {
            $workflow = $this->parameterEngine->apply($workflow, $this->getParameters(), $values);
        } catch (\Throwable $e) {
            // keep the un-substituted body
        }

        $conditions = $workflow['conditions_serialized'] ?? null;

        return $this->plainLanguageRenderer->renderFromFields(
            (string) ($workflow['trigger_type'] ?? ''),
            (string) ($workflow['trigger_ref'] ?? ''),
            (string) ($workflow['entity_type'] ?? ''),
            is_string($conditions) ? $conditions : null,
            (string) json_encode($workflow['definition'] ?? new \stdClass())
        );
    }

    /**
     * Human label for a parameter (localized), falling back to the key.
     *
     * @param array<string, mixed> $parameter
     */
    public function parameterLabel(array $parameter): string
    {
        $label = LocalizedText::resolve($parameter['label'] ?? '', $this->getLocale());
        return $label !== '' ? $label : (string) ($parameter['key'] ?? '');
    }

    public function getInstallUrl(): string
    {
        return $this->getUrl('mageos_workflows/template/install', ['code' => $this->getCode()]);
    }

    /**
     * Preview substitution values: the declared default, else the first option,
     * else a bracketed placeholder — so the preview never trips the leftover-
     * token or required-parameter guards.
     *
     * @return array<string, string>
     */
    private function previewValues(): array
    {
        $values = [];
        foreach ($this->getParameters() as $parameter) {
            $key = (string) ($parameter['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (isset($parameter['default']) && $parameter['default'] !== '') {
                $values[$key] = (string) $parameter['default'];
                continue;
            }
            $options = $parameter['options'] ?? null;
            if (is_array($options) && $options !== [] && isset($options[0]['value'])) {
                $values[$key] = (string) $options[0]['value'];
                continue;
            }
            $values[$key] = '<' . $this->parameterLabel($parameter) . '>';
        }
        return $values;
    }
}
