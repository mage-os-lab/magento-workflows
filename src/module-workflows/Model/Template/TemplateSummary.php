<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

/**
 * Card-grid view of a template: enough to render a gallery card and run the
 * compatibility check, without the full workflow body. Title/description keep
 * their raw envelope shape (string | {locale: string} map) so a consumer
 * resolves them against its own locale.
 */
class TemplateSummary
{
    /**
     * @param mixed $title raw localized text (string | {locale: string})
     * @param mixed $description raw localized text (string | {locale: string})
     * @param array<string, mixed> $requires the template `requires` clause
     * @param array<int, array<string, mixed>> $parameters the template `parameters` list
     */
    public function __construct(
        private readonly string $code,
        private readonly mixed $title,
        private readonly mixed $description,
        private readonly string $category,
        private readonly string $version,
        private readonly array $requires = [],
        private readonly array $parameters = []
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Raw localized title (string | {locale: string} map).
     */
    public function getTitleData(): mixed
    {
        return $this->title;
    }

    public function getTitle(string $locale = LocalizedText::DEFAULT_LOCALE): string
    {
        return LocalizedText::resolve($this->title, $locale);
    }

    /**
     * Raw localized description (string | {locale: string} map).
     */
    public function getDescriptionData(): mixed
    {
        return $this->description;
    }

    public function getDescription(string $locale = LocalizedText::DEFAULT_LOCALE): string
    {
        return LocalizedText::resolve($this->description, $locale);
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Build a summary from a decoded template envelope's `template` object.
     *
     * @param array<string, mixed> $template the envelope's `template` node
     */
    public static function fromTemplateNode(array $template): self
    {
        return new self(
            (string) ($template['code'] ?? ''),
            $template['title'] ?? '',
            $template['description'] ?? '',
            (string) ($template['category'] ?? ''),
            (string) ($template['version'] ?? ''),
            is_array($template['requires'] ?? null) ? $template['requires'] : [],
            is_array($template['parameters'] ?? null) ? $template['parameters'] : []
        );
    }
}
