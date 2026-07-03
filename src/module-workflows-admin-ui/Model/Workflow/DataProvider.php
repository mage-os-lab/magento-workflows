<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Workflow;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Save;

/**
 * Form data provider for the workflow edit form (peer domain collection, not the grid
 * SearchResult -- the form needs full entity fidelity, e.g. website_ids from the join table).
 *
 * Also restores merchant input persisted by the Save controller after a failed save
 * (validation/authorization error): persisted data is merged over the loaded record --
 * or into a synthetic record for new workflows -- and the persistor is cleared.
 */
class DataProvider extends AbstractDataProvider
{
    /**
     * @var array<int|string, array<string, mixed>>|null
     */
    private ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
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

        $persisted = $this->dataPersistor->get(Save::PERSISTOR_KEY);
        if (is_array($persisted) && $persisted !== []) {
            $id = !empty($persisted['workflow_id']) ? (int) $persisted['workflow_id'] : null;
            $this->loadedData[$id] = array_merge($this->loadedData[$id] ?? [], $persisted);
            $this->dataPersistor->clear(Save::PERSISTOR_KEY);
        }

        return $this->loadedData;
    }

    /**
     * The "Execution Log" fieldset embeds the executions listing filtered by workflow_id;
     * on the new-workflow form there is no id to filter by yet, so hide it.
     */
    public function getMeta(): array
    {
        $meta = parent::getMeta();
        if (!$this->request->getParam($this->getRequestFieldName())) {
            $meta['execution_log']['arguments']['data']['config']['visible'] = false;
        }
        return $meta;
    }
}
