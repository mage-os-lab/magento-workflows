<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;

/**
 * Shared doubles for order.create_creditmemo's redelivery-marker dependencies
 * (credit-memo repository + search-criteria builder for the marker scan, and
 * the comment-creation factory for the marker it attaches).
 *
 * Three suites construct the action — CreateCreditmemoTest (config helpers),
 * CreateCreditmemoBehaviorTest (state guard, failure classification),
 * CreateCreditmemoPartialTest (percent/fixed amounts) — and a fourth,
 * CreateCreditmemoDedupeTest, exercises the marker itself. Rather than
 * quadruplicating the same three doubles, they live here; each suite keeps its
 * own order/refund doubles, which differ per suite.
 *
 * Lives in its own PSR-4 file (not inside a *Test.php) so it autoloads under
 * both the standalone runner and real PHPUnit regardless of file order.
 */
trait CreditmemoRefundDoubles
{
    /** @var array<int, array{field: mixed, value: mixed, type: mixed}> filters the action built */
    public array $creditmemoFilters = [];

    /** @var array<int, CreditmemoCommentCreationInterface> every comment the action created */
    public array $createdComments = [];

    /**
     * Credit-memo repository whose getList() returns the given memos (build
     * them with markedCreditmemo()); [] = this order has no memos yet.
     *
     * @param array<int, object> $memos
     */
    protected function creditmemoRepositoryWith(array $memos = []): CreditmemoRepositoryInterface
    {
        return new class ($memos) implements CreditmemoRepositoryInterface {
            public int $listCalls = 0;

            /**
             * @param array<int, object> $memos
             */
            public function __construct(private readonly array $memos)
            {
            }

            public function getList($searchCriteria)
            {
                $this->listCalls++;
                $memos = $this->memos;
                return new class ($memos) {
                    /**
                     * @param array<int, object> $memos
                     */
                    public function __construct(private readonly array $memos)
                    {
                    }

                    public function getItems()
                    {
                        return $this->memos;
                    }
                };
            }

            public function get($id) { throw new \BadMethodCallException(__METHOD__); }
            public function create() { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    /**
     * A credit-memo repository whose search blows up — the "dedupe question
     * unanswered" path, which must never fall through to a refund.
     */
    protected function creditmemoRepositoryThatFails(\Exception $error): CreditmemoRepositoryInterface
    {
        return new class ($error) implements CreditmemoRepositoryInterface {
            public function __construct(private readonly \Exception $error)
            {
            }

            public function getList($searchCriteria)
            {
                throw $this->error;
            }

            public function get($id) { throw new \BadMethodCallException(__METHOD__); }
            public function create() { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    /**
     * A persisted credit memo carrying the given comment bodies — the shape the
     * marker scan walks (repository -> memo -> its comments).
     *
     * @param array<int, string|null> $comments
     */
    protected function markedCreditmemo(int $entityId, array $comments): object
    {
        return new class ($entityId, $comments) {
            /**
             * @param array<int, string|null> $comments
             */
            public function __construct(private readonly int $entityId, private readonly array $comments)
            {
            }

            public function getEntityId()
            {
                return $this->entityId;
            }

            public function getComments()
            {
                $items = [];
                foreach ($this->comments as $text) {
                    $items[] = new class ($text) {
                        public function __construct(private readonly ?string $text)
                        {
                        }

                        public function getComment()
                        {
                            return $this->text;
                        }
                    };
                }
                return $items;
            }
        };
    }

    /**
     * Recording SearchCriteriaBuilder: the real one is a concrete class, so the
     * double extends it (see the shim's note) and captures the filters the
     * action builds into $this->creditmemoFilters.
     */
    protected function creditmemoCriteriaBuilder(): SearchCriteriaBuilder
    {
        $owner = $this;
        return new class ($owner) extends SearchCriteriaBuilder {
            public function __construct(private readonly object $owner)
            {
            }

            public function addFilter($field, $value, $conditionType = 'eq')
            {
                $this->owner->creditmemoFilters[] = [
                    'field' => $field,
                    'value' => $value,
                    'type' => $conditionType,
                ];
                return $this;
            }

            public function create()
            {
                return new DataObject();
            }
        };
    }

    /**
     * Comment-creation factory recording every comment the action builds into
     * $this->createdComments, so tests can assert the marker body and the
     * is_visible_on_front flag it carries.
     */
    protected function creditmemoCommentFactory(): CreditmemoCommentCreationInterfaceFactory
    {
        $owner = $this;
        return new class ($owner) extends CreditmemoCommentCreationInterfaceFactory {
            // Bypass the generated factory's DI constructor (ObjectManager) so
            // the double is instantiable under real Magento.
            public function __construct(private readonly object $owner)
            {
            }

            public function create(array $data = [])
            {
                $comment = new class implements CreditmemoCommentCreationInterface {
                    private ?string $comment = null;
                    private $visibleOnFront = null;

                    public function getComment()
                    {
                        return $this->comment;
                    }

                    public function setComment($comment)
                    {
                        $this->comment = (string)$comment;
                        return $this;
                    }

                    public function getIsVisibleOnFront()
                    {
                        return $this->visibleOnFront;
                    }

                    public function setIsVisibleOnFront($isVisibleOnFront)
                    {
                        $this->visibleOnFront = $isVisibleOnFront;
                        return $this;
                    }

                    public function getExtensionAttributes() { throw new \BadMethodCallException(__METHOD__); }
                    public function setExtensionAttributes($extensionAttributes)
                    {
                        throw new \BadMethodCallException(__METHOD__);
                    }
                };
                $this->owner->createdComments[] = $comment;
                return $comment;
            }
        };
    }
}
