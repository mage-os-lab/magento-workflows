<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\OptionSourceProviderInterface;

/**
 * Same-origin, session-authed JSON option-source feed for config-panel selects
 * (F6 option-source union). A config field's `options_search: {source, min_chars}`
 * points here; the client calls ?source=<code>&q=<query> as the user types
 * (bounded sources return their whole list on an empty query, search-typed
 * sources filter + cap). Delegates to the SAME core provider the REST route
 * (GET /V1/workflows/meta/options) uses; ::view is the auth (read-only). The
 * REST route stays for third parties/CI (Phase A Data/* pattern).
 */
class Options extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::view';

    public function __construct(
        Action\Context $context,
        private readonly OptionSourceProviderInterface $optionSourceProvider
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $source = (string) $this->getRequest()->getParam('source', '');
        if ($source === '') {
            return $result->setHttpResponseCode(400)->setData(['error' => (string) __('Missing source')]);
        }
        $query = $this->getRequest()->getParam('q');
        $query = is_string($query) && $query !== '' ? $query : null;

        try {
            $options = $this->optionSourceProvider->getOptions($source, $query);
        } catch (NoSuchEntityException $e) {
            // An unknown source is a typo in a field's options_search, not a
            // silent empty list — surface it (mirrors the REST behavior).
            return $result->setHttpResponseCode(404)->setData(['error' => $e->getMessage()]);
        }

        $rows = [];
        foreach ($options as $option) {
            $rows[] = ['value' => $option->getValue(), 'label' => $option->getLabel()];
        }
        return $result->setData(['options' => $rows]);
    }
}
