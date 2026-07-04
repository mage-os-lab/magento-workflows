<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Workflow;

use Magento\Framework\App\Action\HttpPostActionInterface;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Conditions;
use PHPUnit\Framework\TestCase;

/**
 * The shared condition slide-out apply target (implementation plan 07, stage 5;
 * E1 seam). Editing conditions is a write-authoring action, so the controller
 * is POST-only and gated by ::manage (the admin router enforces the form key on
 * POST). The serialized-tree round-trip (the "post the tree back" contract) is
 * exercised through the pure normalize() helper: a valid tree canonicalizes and
 * survives, an empty tree becomes null ("always run"), and undecodable input is
 * rejected as a client error.
 *
 * The controller has a heavy Action\Context constructor, so ACL/HTTP are
 * asserted by reflection and normalize() is invoked on an un-constructed
 * instance (it touches no injected dependency).
 */
class ConditionsControllerTest extends TestCase
{
    public function testIsGatedByManageAndPostOnly(): void
    {
        $this->assertSame('MageOS_Workflows::manage', Conditions::ADMIN_RESOURCE);
        $this->assertTrue(
            is_a(Conditions::class, HttpPostActionInterface::class, true),
            'Editing conditions is a write action: POST so the admin router enforces the form key'
        );
    }

    private function normalize(mixed $raw): string|null|false
    {
        $controller = (new \ReflectionClass(Conditions::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Conditions::class, 'normalize');
        $method->setAccessible(true);
        return $method->invoke($controller, $raw);
    }

    public function testValidTreeRoundTrips(): void
    {
        $tree = '{"type":"combine","aggregator":"all","value":"1","conditions":[]}';
        $normalized = $this->normalize($tree);
        $this->assertTrue(is_string($normalized), 'A valid tree normalizes to a JSON string');
        // The decoded tree is identical after the round-trip (canonical re-encode).
        $this->assertEquals(json_decode($tree, true), json_decode((string) $normalized, true));
    }

    public function testEmptyTreeBecomesNull(): void
    {
        $this->assertNull($this->normalize(''));
        $this->assertNull($this->normalize('   '));
        $this->assertNull($this->normalize(null));
    }

    public function testUndecodableInputIsRejected(): void
    {
        $this->assertFalse($this->normalize('{not json'));
        // A scalar JSON value is not a condition tree (must be an object/array).
        $this->assertFalse($this->normalize('42'));
    }
}
