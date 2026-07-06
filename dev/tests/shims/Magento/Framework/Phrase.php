<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\Phrase. Renders %1..%N
 * positional placeholders (no translation).
 */
class Phrase
{
    private string $text;

    private array $arguments;

    public function __construct(string $text, array $arguments = [])
    {
        $this->text = $text;
        $this->arguments = $arguments;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function render(): string
    {
        $result = $this->text;
        $position = 1;
        foreach ($this->arguments as $key => $value) {
            if ($value instanceof self) {
                $value = $value->render();
            } elseif (!is_scalar($value) && $value !== null) {
                $value = (string)json_encode($value);
            }
            $placeholder = is_int($key) ? '%' . $position : '%' . $key;
            $result = str_replace($placeholder, (string)$value, $result);
            $position++;
        }
        return $result;
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
