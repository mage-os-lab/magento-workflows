<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

use MageOS\Workflows\Api\Data\ValidationMessageInterface;

/**
 * Ordered list of validation findings (F2). Errors block the save; warnings
 * travel with it.
 */
class ValidationResult
{
    /**
     * @var ValidationMessageInterface[]
     */
    private readonly array $messages;

    /**
     * @param ValidationMessageInterface[] $messages
     */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $message) {
            if (!$message instanceof ValidationMessageInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Validation messages must implement %s', ValidationMessageInterface::class)
                );
            }
        }
        $this->messages = array_values($messages);
    }

    /**
     * @return ValidationMessageInterface[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return ValidationMessageInterface[]
     */
    public function getErrors(): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (ValidationMessageInterface $m): bool =>
                $m->getSeverity() === ValidationMessageInterface::SEVERITY_ERROR
        ));
    }

    /**
     * @return ValidationMessageInterface[]
     */
    public function getWarnings(): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (ValidationMessageInterface $m): bool =>
                $m->getSeverity() === ValidationMessageInterface::SEVERITY_WARNING
        ));
    }

    public function hasErrors(): bool
    {
        return $this->getErrors() !== [];
    }

    public function isValid(): bool
    {
        return !$this->hasErrors();
    }

    public function hasErrorWithCode(string $code): bool
    {
        foreach ($this->getErrors() as $error) {
            if ($error->getCode() === $code) {
                return true;
            }
        }
        return false;
    }
}
