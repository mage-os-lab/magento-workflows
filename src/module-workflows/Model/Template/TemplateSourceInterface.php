<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

/**
 * A source of gallery templates (06 — Template Gallery, discovery §3 G3).
 *
 * BundledTemplateSource ships now, reading pack directories off disk. A future
 * RemoteTemplateSource implements the same contract plus signature verification
 * behind a default-off config toggle — the gallery UI and install pipeline do
 * not change (discovery §3). The remote source is a future *implementor*, not
 * scaffolding built now.
 *
 * @api
 */
interface TemplateSourceInterface
{
    /**
     * Lightweight summaries for the card grid (code, title, category, requires,
     * version), never the full workflow body.
     *
     * @return TemplateSummary[]
     */
    public function list(): array;

    /**
     * The raw template JSON for a code, validated downstream by TemplateInstaller.
     *
     * @throws \MageOS\Workflows\Model\Template\TemplateNotFoundException
     */
    public function get(string $code): string;

    public function has(string $code): bool;
}
