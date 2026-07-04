<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Template;

use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\Index;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\Install;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template\View;
use PHPUnit\Framework\TestCase;

/**
 * ACL contract (06): every gallery controller — including the install POST
 * target — is gated by MageOS_Workflows::manage. Installing a template creates
 * a workflow, and the per-action authoring gates inside the importer still
 * apply individually (enforced by the F2 ActionAuthorizationCheck, tested in
 * core), so no new ACL resource is needed.
 *
 * The controllers have a heavy Action\Context constructor, so the ADMIN_RESOURCE
 * constant is asserted by reflection on the class, not an instance.
 */
class TemplateAclTest extends TestCase
{
    public function testEveryGalleryControllerRequiresManage(): void
    {
        foreach ([Index::class, View::class, Install::class] as $controller) {
            $this->assertSame(
                'MageOS_Workflows::manage',
                $controller::ADMIN_RESOURCE,
                $controller . ' must be gated by the manage resource'
            );
        }
    }

    public function testInstallCollectsSecretOnlyWhenValueProvided(): void
    {
        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Install::class, 'collectSecrets');
        $method->setAccessible(true);

        // The secret param's VALUE is the secret key name; a value pairs to it.
        $parameters = ['webhook_secret' => 'fraud_hmac', 'other' => 'x'];
        $secretValues = ['webhook_secret' => 's3cr3t', 'other' => ''];

        $result = $method->invoke($install, $parameters, $secretValues);

        $this->assertSame(['fraud_hmac' => 's3cr3t'], $result);
    }

    public function testInstallCollectsNoSecretsWhenValuesBlank(): void
    {
        $install = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Install::class, 'collectSecrets');
        $method->setAccessible(true);

        $result = $method->invoke($install, ['s' => 'my_secret'], ['s' => '']);

        $this->assertSame([], $result);
    }
}
