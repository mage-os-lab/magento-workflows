<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Repository-backed hydration with a per-instance identity map.
 *
 * Entity type => repository mapping:
 *   sales_order     => OrderRepositoryInterface
 *   customer        => CustomerRepositoryInterface
 *   catalog_product => ProductRepositoryInterface
 *
 * Loaded entities are converted to flat DataObjects: model data / DTO arrays
 * with `custom_attributes` and `extension_attributes` lifted to top-level
 * keys, nested API objects converted to arrays, and (for orders) `items`,
 * `payment` and a flat `payment_method` made available — mirroring the shape
 * of the async-events trigger snapshot so conditions read both identically.
 *
 * Note on $fresh: the identity map is always bypassed. Repositories keep
 * their own registries; ProductRepository supports an explicit force-reload,
 * order/customer registries are per-request and acceptable for delayed
 * (fresh consumer process) re-validation.
 */
class HydrationProvider implements HydrationProviderInterface
{
    private const MAX_DEPTH = 6;

    /**
     * Identity map: "<type>:<id>" => DataObject|null (null = known missing)
     *
     * @var array<string, DataObject|null>
     */
    private array $entities = [];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ExtensibleDataObjectConverter $dataObjectConverter,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    public function getEntity(string $entityType, int $entityId, bool $fresh = false): ?DataObject
    {
        if ($entityId <= 0) {
            return null;
        }
        $key = $entityType . ':' . $entityId;
        if (!$fresh && array_key_exists($key, $this->entities)) {
            return $this->entities[$key];
        }
        try {
            $data = match ($entityType) {
                self::TYPE_ORDER => $this->convertOrder($this->orderRepository->get($entityId)),
                self::TYPE_CUSTOMER => $this->convertCustomer($this->customerRepository->getById($entityId)),
                self::TYPE_PRODUCT => $this->convertProduct(
                    $this->productRepository->getById($entityId, false, null, $fresh)
                ),
                default => null,
            };
        } catch (NoSuchEntityException) {
            $data = null;
        } catch (LocalizedException) {
            $data = null;
        }

        $entity = $data === null ? null : $this->dataObjectFactory->create(['data' => $data]);

        return $this->entities[$key] = $entity;
    }

    private function convertOrder(OrderInterface $order): array
    {
        $data = $this->extractData($order, OrderInterface::class);
        $items = [];
        foreach ($order->getItems() ?: [] as $item) {
            $items[] = $this->extractData($item, OrderItemInterface::class);
        }
        $data['items'] = $items;
        $payment = $order->getPayment();
        if ($payment !== null) {
            $data['payment'] = $this->extractData($payment, OrderPaymentInterface::class);
            $data['payment_method'] = $payment->getMethod();
        }
        return $data;
    }

    private function convertCustomer(CustomerInterface $customer): array
    {
        return $this->extractData($customer, CustomerInterface::class);
    }

    private function convertProduct(ProductInterface $product): array
    {
        if ($product instanceof \Magento\Catalog\Model\Product) {
            // Force lazy category link load so `category_ids` is present in data
            $product->getCategoryIds();
        }
        return $this->extractData($product, ProductInterface::class);
    }

    /**
     * Entity/DTO => flat array. Models expose getData(); customer-style DTOs
     * expose a public __toArray(); anything else goes through the converter
     * with its known API interface.
     */
    private function extractData(object $entity, string $dtoInterface): array
    {
        if ($entity instanceof DataObject) {
            $data = $entity->getData();
        } elseif (is_callable([$entity, '__toArray'])) {
            $data = (array)$entity->__toArray();
        } else {
            $data = $this->dataObjectConverter->toNestedArray($entity, [], $dtoInterface);
        }
        $data = $this->liftApiStructures(is_array($data) ? $data : []);

        $sanitized = $this->sanitize($data, 0);

        return is_array($sanitized) ? $sanitized : [];
    }

    /**
     * Lift custom_attributes ([{attribute_code, value}, ...]) and
     * extension_attributes to top-level keys — never overwriting flat data.
     * This is what makes custom EAV attributes addressable by conditions.
     */
    private function liftApiStructures(array $data): array
    {
        $custom = $data['custom_attributes'] ?? null;
        if (is_array($custom)) {
            foreach ($custom as $attribute) {
                $code = null;
                $value = null;
                if ($attribute instanceof AttributeInterface) {
                    $code = $attribute->getAttributeCode();
                    $value = $attribute->getValue();
                } elseif (is_array($attribute)) {
                    $code = $attribute[AttributeInterface::ATTRIBUTE_CODE] ?? null;
                    $value = $attribute[AttributeInterface::VALUE] ?? null;
                }
                if (is_string($code) && $code !== '' && !array_key_exists($code, $data)) {
                    $data[$code] = $value;
                }
            }
            unset($data['custom_attributes']);
        }

        $extension = $data['extension_attributes'] ?? null;
        if (is_object($extension) && is_callable([$extension, '__toArray'])) {
            $extension = $extension->__toArray();
        }
        if (is_array($extension)) {
            foreach ($extension as $code => $value) {
                if (is_string($code) && !array_key_exists($code, $data)) {
                    $data[$code] = $value;
                }
            }
        }
        unset($data['extension_attributes']);

        return $data;
    }

    /**
     * Recursively reduce to scalars/nulls/arrays; nested models and DTOs are
     * converted, unconvertible objects dropped. Depth-limited defensively.
     */
    private function sanitize(mixed $value, int $depth): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }
        if ($value instanceof DataObject) {
            $value = $value->getData();
        } elseif (is_object($value) && is_callable([$value, '__toArray'])) {
            $value = $value->__toArray();
        }
        if (!is_array($value)) {
            return null;
        }
        $value = $this->liftApiStructures($value);
        $result = [];
        foreach ($value as $key => $item) {
            $sanitized = $this->sanitize($item, $depth + 1);
            if ($sanitized !== null || $item === null) {
                $result[$key] = $sanitized;
            }
        }
        return $result;
    }
}
