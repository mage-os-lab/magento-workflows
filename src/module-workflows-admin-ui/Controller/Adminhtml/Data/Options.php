<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\OptionSourceProviderInterface;

/**
 * Same-origin, admin-authed JSON twin of the REST route
 * GET /V1/workflows/meta/options (F6 option-source union), delegating to the
 * SAME core provider; ::view is the auth (read-only). Consumed by BOTH admin
 * surfaces that need to resolve options in the browser: the template install
 * form's search pickers (a parameter's `options_search: {source, min_chars}`
 * or its `entity:*` registry mapping) and the canvas config panel. The client
 * calls ?source=<code>&q=<query> as the user types — bounded sources return
 * their whole list on an empty query, search-typed sources filter + cap. The
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
