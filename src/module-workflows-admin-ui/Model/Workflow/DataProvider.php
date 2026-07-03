<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Workflow;

use Magento\Ui\DataProvider\AbstractDataProvider;
use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;

/**
 * Form data provider for the workflow edit form (peer domain collection, not the grid
 * SearchResult -- the form needs full entity fidelity, e.g. website_ids from the join table).
 */
class DataProvider extends AbstractDataProvider
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $collectionFactory, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        foreach ($this->collection->getItems() as $model) {
            $row = $model->getData();
            if (method_exists($model, 'getWebsiteIds')) {
                $row['website_ids'] = $model->getWebsiteIds();
            }
            $this->loadedData[$model->getId()] = $row;
        }

        return $this->loadedData;
    }
}
