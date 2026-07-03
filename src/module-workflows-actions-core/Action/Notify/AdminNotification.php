<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Notify;

use Magento\Framework\Notification\NotifierInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * notify.admin — drops a notice into the admin notification inbox.
 */
class AdminNotification extends AbstractAction implements SimulateableActionInterface
{
    public function __construct(
        private readonly NotifierInterface $notifier
    ) {
    }

    public function getCode(): string
    {
        return 'notify.admin';
    }

    public function getLabel(): string
    {
        return (string)__('Admin Notification');
    }

    public function getGroup(): string
    {
        return (string)__('Notify');
    }

    public function getApplicableEntities(): array
    {
        return [];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $title = $this->stringConfig($config, 'title');
        if ($title === null) {
            return $this->missingConfig('title');
        }
        $description = $this->stringConfig($config, 'description', '');

        try {
            $this->notifier->addNotice($title, (string)$description);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not add admin notification: ' . $e->getMessage(), true);
        }

        return ActionResult::success(['title' => $title]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $title = $this->stringConfig($config, 'title');
        if ($title === null) {
            return $this->missingConfig('title');
        }
        return $this->simulated(sprintf('Add admin inbox notice "%s"', $title));
    }
}
