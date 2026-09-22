<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace CloudEvents\V1;

/**
 * Standalone-runner shim for cloudevents/sdk-php V1/CloudEventImmutable.
 * The real class is FINAL, so tests construct it directly; this shim keeps
 * the real constructor signature and the accessors the notifier reads.
 */
final class CloudEventImmutable
{
    /**
     * @param mixed $data
     */
    public function __construct(
        private string $id,
        private string $source,
        private string $type,
        private $data = null,
        private ?string $dataContentType = null,
        private ?string $dataSchema = null,
        private ?string $subject = null,
        private ?\DateTimeImmutable $time = null,
        private array $extensions = []
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    public function getDataContentType(): ?string
    {
        return $this->dataContentType;
    }

    public function getDataSchema(): ?string
    {
        return $this->dataSchema;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->time;
    }

    public function getExtensions(): array
    {
        return $this->extensions;
    }
}
