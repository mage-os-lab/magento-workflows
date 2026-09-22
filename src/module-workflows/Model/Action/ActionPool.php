<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Action;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionMetadataInterface;

/**
 * DI-registered pool of workflow actions, keyed by action code.
 * This pool IS the connector SDK surface: one class + one di.xml entry.
 */
class ActionPool
{
    /**
     * @param ActionInterface[] $actions code => instance
     */
    public function __construct(
        private readonly array $actions = []
    ) {
        foreach ($this->actions as $code => $action) {
            if (!$action instanceof ActionInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Workflow action "%s" must implement %s', $code, ActionInterface::class)
                );
            }
        }
    }

    public function has(string $code): bool
    {
        return isset($this->actions[$code]);
    }

    public function get(string $code): ActionInterface
    {
        if (!isset($this->actions[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow action "%s"', $code));
        }
        return $this->actions[$code];
    }

    /**
     * @return ActionInterface[] code => instance
     */
    public function getAll(): array
    {
        return $this->actions;
    }

    /**
     * Actions exposing UI metadata, optionally filtered by entity type
     *
     * @return ActionMetadataInterface[] code => instance
     */
    public function getMetadata(?string $entityType = null): array
    {
        $result = [];
        foreach ($this->actions as $code => $action) {
            if (!$action instanceof ActionMetadataInterface) {
                continue;
            }
            $applicable = $action->getApplicableEntities();
            if ($entityType !== null && $applicable !== [] && !in_array($entityType, $applicable, true)) {
                continue;
            }
            $result[$code] = $action;
        }
        return $result;
    }
}
