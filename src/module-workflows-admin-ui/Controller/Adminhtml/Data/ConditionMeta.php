<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MageOS\Workflows\Model\Rule\ConditionMetaProvider;

/**
 * Same-origin, admin-authed condition-tree metadata feed for the condition
 * builder (docs/11-admin-ui.md "Condition slide-out"). Placed beside the option
 * proxy (Data/Options) for the same reason: it is a read-only projection of a
 * CORE service — MageOS\Workflows\Model\Rule\ConditionMetaProvider, which
 * interrogates the registered condition classes — consumed by more than one
 * admin surface, so it is one route rather than a per-surface copy.
 *
 * GET-only and gated by ::view (like the option feed): an operator who may look
 * at a workflow must be able to read the labels and operator sets behind its
 * condition tree. Authoring still posts through the ::manage apply target
 * (Workflow/Conditions).
 *
 * Params: `entity_type` (required — the workflow's entity type, which selects
 * the condition root) and `node_type` (optional FQCN of the node being edited;
 * defaults to the entity root). The provider validates `node_type` against the
 * set reachable from that root before instantiating anything, so a hostile or
 * stale FQCN is a 400, never a class load.
 */
class ConditionMeta extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::view';

    public function __construct(
        Action\Context $context,
        private readonly ConditionMetaProvider $conditionMetaProvider
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        [$httpCode, $payload] = $this->buildResponse(
            trim((string) $this->getRequest()->getParam('entity_type', '')),
            trim((string) $this->getRequest()->getParam('node_type', ''))
        );
        if ($httpCode !== 200) {
            $result->setHttpResponseCode($httpCode);
        }
        return $result->setData($payload);
    }

    /**
     * The whole response decision, free of the request/result plumbing so it is
     * directly testable (peer convention: Workflow\Conditions::normalize()).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildResponse(string $entityType, string $nodeType): array
    {
        if ($entityType === '') {
            return [400, ['success' => false, 'error' => (string) __('Missing entity_type')]];
        }

        try {
            $root = $this->conditionMetaProvider->getRootType($entityType);
            $node = $this->conditionMetaProvider->getMetaForNode(
                $entityType,
                $nodeType !== '' ? $nodeType : $root
            );
        } catch (\InvalidArgumentException $e) {
            // An unregistered entity type or an unreachable/non-condition node
            // type is a client error — a stale bookmark or a tampered request,
            // not a server fault. The message names the offending value so the
            // builder can report it instead of silently rendering nothing.
            return [400, ['success' => false, 'error' => $e->getMessage()]];
        } catch (\Exception $e) {
            // A condition class that cannot answer the native metadata calls
            // (broken third-party wiring) must not leak its internals to the
            // browser; the builder falls back to read-only raw JSON for the node.
            return [500, ['success' => false, 'error' => (string) __('The condition metadata could not be loaded.')]];
        }

        return [200, ['success' => true, 'root' => $root, 'node' => $node]];
    }
}
