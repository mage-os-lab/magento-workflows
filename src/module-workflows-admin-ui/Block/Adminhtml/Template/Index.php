<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Template;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Locale\ResolverInterface;
use MageOS\Workflows\Model\Template\CompatibilityChecker;
use MageOS\Workflows\Model\Template\CompatibilityResult;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\Workflows\Model\Template\TemplateSummary;

/**
 * The gallery card grid (06): one card per template with a compatibility badge.
 * Incompatible templates render greyed-out with their typed reasons (which
 * double as ecosystem marketing for connector packages). Server-rendered — no
 * ui_component grid needed for ~15-50 items.
 */
class Index extends Template
{
    /** @var array<string, CompatibilityResult> memoized per code */
    private array $compat = [];

    public function __construct(
        Context $context,
        private readonly TemplateSourceInterface $templateSource,
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly ResolverInterface $localeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return TemplateSummary[]
     */
    public function getSummaries(): array
    {
        $summaries = $this->templateSource->list();
        usort(
            $summaries,
            static fn (TemplateSummary $a, TemplateSummary $b): int =>
                [$a->getCategory(), $a->getCode()] <=> [$b->getCategory(), $b->getCode()]
        );
        return $summaries;
    }

    public function isCompatible(TemplateSummary $summary): bool
    {
        return $this->compatibility($summary)->isCompatible();
    }

    /**
     * @return string[]
     */
    public function getReasons(TemplateSummary $summary): array
    {
        return $this->compatibility($summary)->getMessages();
    }

    public function getTitle(TemplateSummary $summary): string
    {
        return $summary->getTitle($this->getLocale());
    }

    public function getDescription(TemplateSummary $summary): string
    {
        return $summary->getDescription($this->getLocale());
    }

    public function getViewUrl(TemplateSummary $summary): string
    {
        return $this->getUrl('mageos_workflows/template/view', ['code' => $summary->getCode()]);
    }

    public function getInstallUrl(TemplateSummary $summary): string
    {
        return $this->getUrl('mageos_workflows/template/install', ['code' => $summary->getCode()]);
    }

    public function getLocale(): string
    {
        $locale = (string) $this->localeResolver->getLocale();
        return $locale !== '' ? $locale : LocalizedText::DEFAULT_LOCALE;
    }

    private function compatibility(TemplateSummary $summary): CompatibilityResult
    {
        return $this->compat[$summary->getCode()]
            ??= $this->compatibilityChecker->check($summary, $this->getLocale());
    }
}
