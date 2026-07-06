<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Template;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Locale\ResolverInterface;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\Workflows\Model\Template\TemplateSummary;

/**
 * Shared parsing for the detail (View) and install form (Install) blocks: pulls
 * the raw template JSON by the request `code`, splits it into the `template`
 * and `workflow` nodes, and exposes the summary + compatibility.
 */
abstract class AbstractDetail extends Template
{
    /** @var array<string, mixed>|null decoded envelope, memoized */
    private ?array $envelope = null;

    private bool $decoded = false;

    public function __construct(
        Context $context,
        protected readonly TemplateSourceInterface $templateSource,
        protected readonly CompatibilityChecker $compatibilityChecker,
        protected readonly ResolverInterface $localeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCode(): string
    {
        return (string) $this->getRequest()->getParam('code');
    }

    public function getSummary(): ?TemplateSummary
    {
        $node = $this->getTemplateNode();
        return $node === null ? null : TemplateSummary::fromTemplateNode($node);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getParameters(): array
    {
        $summary = $this->getSummary();
        return $summary === null ? [] : $summary->getParameters();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequires(): array
    {
        $summary = $this->getSummary();
        return $summary === null ? [] : $summary->getRequires();
    }

    public function getLocale(): string
    {
        $locale = (string) $this->localeResolver->getLocale();
        return $locale !== '' ? $locale : LocalizedText::DEFAULT_LOCALE;
    }

    public function getGalleryUrl(): string
    {
        return $this->getUrl('mageos_workflows/template/index');
    }

    /**
     * @return array<string, mixed>|null the envelope's `template` node
     */
    protected function getTemplateNode(): ?array
    {
        $node = $this->envelope()['template'] ?? null;
        return is_array($node) ? $node : null;
    }

    /**
     * @return array<string, mixed>|null the envelope's `workflow` node
     */
    protected function getWorkflowNode(): ?array
    {
        $node = $this->envelope()['workflow'] ?? null;
        return is_array($node) ? $node : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        if (!$this->decoded) {
            $this->decoded = true;
            $code = $this->getCode();
            if ($code !== '' && $this->templateSource->has($code)) {
                $decoded = json_decode($this->templateSource->get($code), true);
                $this->envelope = is_array($decoded) ? $decoded : null;
            }
        }
        return $this->envelope ?? [];
    }
}
