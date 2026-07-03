<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\AbstractAction;

/**
 * customer.set_attribute — writes a custom attribute value on the customer.
 *
 * SECURITY (docs/10-security.md, deferred privilege escalation): workflows run
 * later with system privileges, so this action refuses system/ACL-relevant
 * attributes via a deny-by-default list shipped in di.xml ($deniedAttributes):
 * password_hash, rp_token, rp_token_created_at, is_active, group_id,
 * website_id, store_id, confirmation, email. A denied code is a terminal
 * (non-retryable) failure. The attribute code itself is static config —
 * interpolation supplies values, never structure — but the denylist holds even
 * if an author tries to smuggle one in directly.
 */
class SetAttribute extends AbstractAction implements SimulateableActionInterface
{
    private const CODE_PATTERN = '/^[a-zA-Z0-9_]+$/';

    /**
     * @param string[] $deniedAttributes lower-case attribute codes that may never be written
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly array $deniedAttributes = []
    ) {
    }

    public function getCode(): string
    {
        return 'customer.set_attribute';
    }

    public function getLabel(): string
    {
        return 'Set Customer Attribute';
    }

    public function getGroup(): string
    {
        return 'Customer';
    }

    public function getApplicableEntities(): array
    {
        return ['customer'];
    }

    public function getConfigForm(): array
    {
        return [
            [
                'name' => 'attribute_code',
                'label' => 'Attribute Code',
                'type' => 'text',
                'required' => true,
                'notice' => 'System attributes (password_hash, group_id, email, ...) are refused.',
            ],
            ['name' => 'value', 'label' => 'Value', 'type' => 'text', 'required' => true],
        ];
    }

    public function execute(ExecutionContext $ctx, array $config): ActionResult
    {
        $attributeCode = $this->stringConfig($config, 'attribute_code');
        if ($attributeCode === null) {
            return $this->missingConfig('attribute_code');
        }
        if (!array_key_exists('value', $config)) {
            return $this->missingConfig('value');
        }
        $value = $config['value'];

        $denied = $this->checkDenied($attributeCode);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->attributeRepository->get('customer', $attributeCode);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Customer attribute "%s" does not exist', $attributeCode));
        }

        try {
            $customer = $this->customerRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Customer %d not found', $ctx->getEntityId()));
        }

        try {
            $customer->setCustomAttribute($attributeCode, $value);
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not set customer attribute: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'attribute_code' => $attributeCode,
            'value' => $value,
        ]);
    }

    public function simulate(ExecutionContext $ctx, array $config): ActionResult
    {
        $attributeCode = $this->stringConfig($config, 'attribute_code');
        if ($attributeCode === null) {
            return $this->missingConfig('attribute_code');
        }
        $denied = $this->checkDenied($attributeCode);
        if ($denied !== null) {
            return $denied;
        }
        return $this->simulated(sprintf(
            'Set customer %d attribute "%s" to "%s"',
            $ctx->getEntityId(),
            $attributeCode,
            is_scalar($config['value'] ?? null) ? (string)$config['value'] : gettype($config['value'] ?? null)
        ));
    }

    /**
     * Denylist + syntax gate; null when the code is writable
     */
    private function checkDenied(string $attributeCode): ?ActionResult
    {
        if (!preg_match(self::CODE_PATTERN, $attributeCode)) {
            return ActionResult::failure(sprintf('Invalid attribute code "%s"', $attributeCode));
        }
        if (in_array(strtolower($attributeCode), array_map('strtolower', $this->deniedAttributes), true)) {
            return ActionResult::failure(sprintf(
                'Attribute "%s" is on the security denylist and cannot be written by workflows',
                $attributeCode
            ));
        }
        return null;
    }
}
