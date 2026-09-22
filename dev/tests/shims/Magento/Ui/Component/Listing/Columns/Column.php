<?php

declare(strict_types=1);

namespace Magento\Ui\Component\Listing\Columns;

/**
 * Standalone-runner shim: the base UI listing column. The grid-column tests never
 * build a UI component context — they instantiate an anonymous subclass with a
 * no-op constructor (the Backend\Block\Template shim's posture), set the column
 * name, and call prepareDataSource directly. Only getData()/setData() are needed,
 * since that is the whole surface the column classes use from the real
 * DataObject/AbstractComponent chain.
 */
class Column
{
    /** @var array<string, mixed> */
    private array $shimData = [];

    /**
     * @param string $key
     * @return mixed
     */
    public function getData($key = '', $index = null)
    {
        if ($key === '') {
            return $this->shimData;
        }
        return $this->shimData[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setData($key, $value = null)
    {
        $this->shimData[$key] = $value;
        return $this;
    }
}
