<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Api\DefinitionValidationInterface;

/**
 * Condition slide-out apply target (E1 seam). Shared by the canvas node panel
 * and the classic form's Conditions tab: the slide-out posts a serialized
 * condition tree here, the controller shape-validates it through the SAME F2
 * pipeline (ConditionsShapeCheck, via DefinitionValidationInterface) that a
 * save runs, and echoes the normalized tree back — the "post the serialized
 * tree back" contract. ::manage + form key (POST): editing conditions is a
 * write-authoring action.
 *
 * E1 spike verdict (docs/discovery/canvas.md §6, docs/11-admin-ui.md): the
 * stock rule-widget rendering layer (Magento\Rule\Block\Conditions +
 * VarienRulesForm) is NOT a dependency of this module and cannot be exercised
 * or evidenced in this repo/CI, and it is not wired into the classic form
 * either — so the shipped slide-out is the JSON "edit as JSON" editor (E3),
 * hosted by this same controller. When a full install adds the rule widget,
 * it renders into the slide-out fragment and posts its serialized tree to this
 * unchanged endpoint — the contract does not move.
 */
class Conditions extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    /**
     * A trivial valid definition so structural checks pass and only the
     * conditions shape is judged. Declares the CURRENT schema
     * (Definition::SCHEMA_VERSION) — a probe pinned to a legacy version would
     * be silently normalized upward on parse and, once 3 leaves the accepted
     * input list, would fail the very structural check it exists to satisfy.
     */
    private const PROBE_DEFINITION = '{"schema":4,"entry":"s1","steps":{"s1":{"type":"stop"}}}';

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
        $raw = $this->getRequest()->getParam('conditions_serialized');
        $normalized = $this->normalize($raw);

        // An empty tree ("always run") is valid and round-trips to null.
        if ($normalized === null) {
            return $result->setData(['success' => true, 'valid' => true, 'conditions_serialized' => null, 'messages' => []]);
        }
        if ($normalized === false) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'valid' => false,
                'error' => (string) __('Conditions must be a JSON condition tree.'),
            ]);
        }

        try {
            $validation = $this->definitionValidation->validate(
                self::PROBE_DEFINITION,
                $normalized,
                (string) $this->getRequest()->getParam('trigger_type', ''),
                (string) $this->getRequest()->getParam('trigger_ref', ''),
                (string) $this->getRequest()->getParam('entity_type', '')
            );
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'error' => (string) __('The conditions could not be validated.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'valid' => $validation->getValid(),
            // Round-trip: the normalized tree the slide-out should store back.
            'conditions_serialized' => $normalized,
            'messages' => $this->serializeMessages($validation->getMessages()),
        ]);
    }

    /**
     * Normalize the posted conditions to a canonical JSON string, null (empty =
     * "always run"), or false (undecodable — a client error).
     *
     * @return string|null|false
     */
    private function normalize(mixed $raw): string|null|false
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return false;
        }
        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);
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
