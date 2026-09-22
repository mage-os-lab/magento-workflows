<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Api\DefinitionValidationInterface;

/**
 * Backs the workflow edit form's "Refresh preview" action.
 *
 * Runs the posted (unsaved) definition + conditions through the same F2
 * validation pipeline a save would run, in dry-run mode, and returns the
 * plain-language rendering plus every validation finding as JSON. Uses an
 * admin route (session-authenticated) rather than the REST
 * /V1/workflows/validate endpoint so the form never has to mint an integration
 * token; the pipeline itself is shared (DefinitionValidationInterface).
 */
class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly DefinitionValidationInterface $definitionValidation
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();

        $definition = (string) $request->getParam('definition', '');
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

            return $result->setData([
                'success' => true,
                'valid' => $validation->getValid(),
                'plain_language' => $validation->getPlainLanguage(),
                'messages' => $this->serializeMessages($validation->getMessages()),
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'error' => (string) __('The preview could not be generated for this definition.'),
            ]);
        }
    }

    /**
     * @param ValidationMessageInterface[] $messages
     * @return array<int, array<string, mixed>>
     */
    private function serializeMessages(array $messages): array
    {
        $serialized = [];
        foreach ($messages as $message) {
            $serialized[] = [
                'severity' => $message->getSeverity(),
                'code' => $message->getCode(),
                'message' => $message->getMessage(),
                'step_key' => $message->getStepKey(),
                'edge' => $message->getEdge(),
            ];
        }
        return $serialized;
    }
}
