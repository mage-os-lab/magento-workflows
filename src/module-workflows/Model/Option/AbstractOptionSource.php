<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Option;

use MageOS\Workflows\Api\OptionSourceInterface;

/**
 * Base for option sources: the query-filtering + result-capping logic lives
 * here as pure, shim-testable code. Subclasses only supply the raw catalogue
 * via {@see loadOptions()}; live Magento coupling stays in those leaf classes.
 */
abstract class AbstractOptionSource implements OptionSourceInterface
{
    /**
     * Hard cap on returned rows (protects the metadata endpoint against a
     * large search source returning thousands of matches).
     */
    protected const RESULT_LIMIT = 50;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    abstract protected function loadOptions(): array;

    /**
     * @inheritDoc
     */
    public function fetch(?string $query = null): array
    {
        return self::filter($this->loadOptions(), $query, static::RESULT_LIMIT);
    }

    /**
     * @inheritDoc
     */
    public function hasValue(string $value): bool
    {
        foreach ($this->loadOptions() as $option) {
            // Source values are already string-cast on load, so compare strictly.
            if (($option['value'] ?? null) === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Case-insensitive substring filter over value + label, then cap. Pure.
     *
     * @param array<int, array{value: string, label: string}> $options
     * @return array<int, array{value: string, label: string}>
     */
    public static function filter(array $options, ?string $query, int $limit): array
    {
        $needle = $query !== null ? trim($query) : '';
        if ($needle !== '') {
            $lower = mb_strtolower($needle);
            $options = array_values(array_filter(
                $options,
                static fn (array $o): bool =>
                    str_contains(mb_strtolower((string) ($o['value'] ?? '')), $lower)
                    || str_contains(mb_strtolower((string) ($o['label'] ?? '')), $lower)
            ));
        } else {
            $options = array_values($options);
        }

        return $limit > 0 ? array_slice($options, 0, $limit) : $options;
    }
}
