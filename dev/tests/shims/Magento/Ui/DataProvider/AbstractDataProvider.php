<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Ui\DataProvider;

/**
 * Standalone-runner shim: the UI form/listing data-provider base. Only the
 * surface the workflow form DataProvider relies on is reproduced -- the shared
 * collection property, the request-field accessor, and getMeta() returning the
 * configured meta. The tested subclass is built via reflection
 * (newInstanceWithoutConstructor), so this constructor is never invoked by the
 * standalone runner; it mirrors the real signature for fidelity.
 */
abstract class AbstractDataProvider
{
    /**
     * @var mixed
     */
    protected $collection;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $primaryFieldName;

    /**
     * @var string
     */
    protected $requestFieldName;

    /**
     * @var array<string, mixed>
     */
    protected $meta;

    /**
     * @var array<string, mixed>
     */
    protected $data;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        array $meta = [],
        array $data = []
    ) {
        $this->name = $name;
        $this->primaryFieldName = $primaryFieldName;
        $this->requestFieldName = $requestFieldName;
        $this->meta = $meta;
        $this->data = $data;
    }

    public function getRequestFieldName()
    {
        return $this->requestFieldName;
    }

    public function getMeta()
    {
        return $this->meta;
    }
}
