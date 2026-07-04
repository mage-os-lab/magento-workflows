<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Api\DefinitionValidationInterface;

/**
 * Same-origin, session-authed JSON validate proxy for the canvas editor's
 * debounced continuous-validation loop (Phase B). POST (the admin router
 * enforces the form key), gated by ::manage. Delegates to the SAME core
 * service the REST route (POST /V1/workflows/validate) and the classic form's
 * "Refresh preview" use — DefinitionValidationInterface — so the server stays
 * the sole validation authority and the canvas re-implements no rules. The REST
 * route stays available for third parties/CI; this admin proxy exists so the
 * canvas never has to mint an integration token (F6 / Phase A Data/* pattern).
 *
 * Findings come back with step_key/edge so the editor can pin each message to
 * its node; the server strings are returned as data and rendered as text nodes
 * client-side (never innerHTML).
 */
class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly DefinitionValidationInterface $definitionValidation
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();

        $definition = (string) $request->getParam('definition', '');
        if (trim($definition) === '') {
            return $result->setHttpResponseCode(400)
                ->setData(['success' => false, 'error' => (string) __('Missing definition')]);
        }

        $conditions = $request->getParam('conditions_serialized');
        $conditions = is_string($conditions) && $conditions !== '' ? $conditions : null;

        try {
            $validation = $this->definitionValidation->validate(
                $definition,
                $conditions,
                (string) $request->getParam('trigger_type', ''),
                (string) $request->getParam('trigger_ref', ''),
                (string) $request->getParam('entity_type', '')
            );
        } catch (\Exception $e) {
            // A malformed definition (fromJson parse error) is a client-fixable
            // authoring state, not a server fault: report it, do not 500.
            return $result->setData([
                'success' => false,
                'error' => (string) __('The definition could not be validated.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'valid' => $validation->getValid(),
            'plain_language' => $validation->getPlainLanguage(),
            'messages' => $this->serializeMessages($validation->getMessages()),
        ]);
    }

    /**
     * @param ValidationMessageInterface[] $messages
     * @return array<int, array<string, mixed>>
     */
    private function serializeMessages(array $messages): array
    {
        $rows = [];
        foreach ($messages as $message) {
            $rows[] = [
                'severity' => $message->getSeverity(),
                'code' => $message->getCode(),
                'message' => $message->getMessage(),
                'step_key' => $message->getStepKey(),
                'edge' => $message->getEdge(),
            ];
        }
        return $rows;
    }
}
