<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model\Rule\Hydrator;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use MageOS\Workflows\Model\Rule\Hydrator\EntityHydratorInterface;

/**
 * newsletter_subscriber phase-2 hydrator (SUB-C1): loads the flat subscriber
 * DataObject so conditions read hydrated subscribers and trigger snapshots
 * identically. Registered under entity type 'newsletter_subscriber' in the
 * engine's HydrationProvider (etc/di.xml).
 *
 * KNOWN LIMITATION — no repository, load-by-factory only. Magento ships no
 * SubscriberRepositoryInterface / SearchCriteria surface for newsletter
 * subscribers, so this hydrator loads through SubscriberFactory
 * (SubscriberHydrationService::getById) by primary key. Two consequences,
 * documented honestly rather than solved in v1:
 *   - There is no scheduler QueryRunner map entry for newsletter_subscriber
 *     (QueryRunner needs a repository + ConditionToSearchCriteria). Schedule-type
 *     workflows over subscribers are therefore UNSUPPORTED in v1 — only the
 *     event trigger (newsletter.subscription_changed) and its fan-out/wait paths
 *     drive newsletter_subscriber workflows.
 *   - Fresh re-reads ($fresh) still work (a new load per call); the
 *     HydrationProvider's identity map handles memoization the same as for the
 *     repository-backed entities.
 */
class SubscriberHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly SubscriberHydrationService $hydrationService,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    public function hydrate(int $entityId): ?DataObject
    {
        $data = $this->hydrationService->getById($entityId);
        if ($data === []) {
            return null;
        }
        return $this->dataObjectFactory->create(['data' => $data]);
    }
}
