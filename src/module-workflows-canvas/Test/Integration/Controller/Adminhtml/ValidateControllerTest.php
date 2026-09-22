<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Integration\Controller\Adminhtml;

use Magento\Framework\Data\Form\FormKey;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Plan #27 (docs/20-integration-test-plan.md §6): the canvas Validate proxy
 * (mageos_workflows_canvas/data/validate, POST, gated ::manage). It delegates to
 * the SAME core DefinitionValidationInterface the REST route and the classic
 * form use — the server stays the sole validation authority. An invalid
 * definition returns findings (valid=false + messages), and posting an invalid
 * root conditions_serialized returns a ROOT-level conditions finding
 * (CONDITIONS_INVALID_JSON, no step_key) — the fixed canvas finding, now pinned
 * server-side. ACL has-access / no-access come from AbstractBackendController via
 * $uri/$resource.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ValidateControllerTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $uri = 'backend/mageos_workflows_canvas/data/validate';

    /**
     * @var string
     */
    protected $resource = 'MageOS_Workflows::manage';

    /**
     * @var string
     */
    protected $httpMethod = 'POST';

    public function testValidDefinitionReturnsValidTrueWithNoErrors(): void
    {
        $response = $this->postValidate(['definition' => $this->linearDefinition()]);

        $this->assertTrue($response['success']);
        $this->assertTrue($response['valid'], 'A well-formed linear definition validates');
        $this->assertSame([], $this->errorMessages($response));
    }

    public function testInvalidDefinitionReturnsFindings(): void
    {
        // Parses fine (fromJson does not resolve action codes) but the pipeline's
        // action-code check flags the unknown action => valid=false + findings.
        $definition = (string) json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.this_action_does_not_exist', 'next' => null],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->postValidate(['definition' => $definition]);

        $this->assertTrue($response['success'], 'A validatable-but-invalid definition is reported, not 500ed');
        $this->assertFalse($response['valid']);
        $this->assertNotEmpty($response['messages'], 'Findings must come back for the client to pin to nodes');
        foreach ($response['messages'] as $message) {
            $this->assertArrayHasKey('severity', $message);
            $this->assertArrayHasKey('code', $message);
            $this->assertArrayHasKey('message', $message);
            $this->assertArrayHasKey('step_key', $message);
        }
    }

    public function testInvalidRootConditionsReturnRootConditionsFinding(): void
    {
        $response = $this->postValidate([
            'definition' => $this->linearDefinition(),
            // Not a JSON tree — the ConditionsShapeCheck rejects it at the root.
            'conditions_serialized' => 'this-is-not-json',
        ]);

        $this->assertTrue($response['success']);
        $this->assertFalse($response['valid'], 'An unparseable root condition tree invalidates the workflow');

        $rootConditionFindings = array_filter(
            $response['messages'],
            static fn (array $m): bool =>
                $m['code'] === 'CONDITIONS_INVALID_JSON' && ($m['step_key'] === null || $m['step_key'] === '')
        );
        $this->assertNotEmpty(
            $rootConditionFindings,
            'A posted invalid root conditions_serialized must surface a ROOT-level conditions finding'
        );
    }

    public function testMissingDefinitionIsABadRequest(): void
    {
        $response = $this->postValidate(['definition' => '']);

        $this->assertSame(400, $this->getResponse()->getHttpResponseCode());
        $this->assertFalse($response['success']);
    }

    private function linearDefinition(): string
    {
        return (string) json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> the decoded JSON response body
     */
    private function postValidate(array $data): array
    {
        $data['form_key'] = $this->_objectManager->get(FormKey::class)->getFormKey();
        $this->getRequest()->setMethod('POST')->setPostValue($data);
        $this->dispatch($this->uri);

        $decoded = json_decode((string) $this->getResponse()->getBody(), true);
        $this->assertIsArray($decoded, 'The validate proxy must return a JSON object');
        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function errorMessages(array $response): array
    {
        return array_values(array_filter(
            $response['messages'] ?? [],
            static fn (array $m): bool => ($m['severity'] ?? null) === 'error'
        ));
    }
}
