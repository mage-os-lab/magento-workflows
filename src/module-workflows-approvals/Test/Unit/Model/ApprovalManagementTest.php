<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use MageOS\WorkflowsApprovals\Model\ApprovalManagement;
use PHPUnit\Framework\TestCase;

/**
 * REST actor resolution (§5): the web-api user context maps to the decision
 * path's (actorType, actorId) pair — integration tokens to 'integration',
 * everything else (admin sessions/tokens) to 'admin'. Exercised against the
 * real UserContextInterface constants (shimmed with the real values —
 * INTEGRATION = 1, ADMIN = 2): this class was previously untested, which is
 * how a wrong user_type pinning survived Stage 3 review elsewhere.
 * resolveActor() is invoked via reflection (mirrors admin-ui's
 * TemplateAclTest posture for classes with heavy constructors) so the test
 * needs no ApprovalService double.
 */
class ApprovalManagementTest extends TestCase
{
    private function userContext(?int $userType, ?int $userId): UserContextInterface
    {
        return new class($userType, $userId) implements UserContextInterface {
            public function __construct(private readonly ?int $userType, private readonly ?int $userId)
            {
            }

            public function getUserId()
            {
                return $this->userId;
            }

            public function getUserType()
            {
                return $this->userType;
            }
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveActor(UserContextInterface $userContext): array
    {
        $management = (new \ReflectionClass(ApprovalManagement::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(ApprovalManagement::class, 'userContext');
        $property->setValue($management, $userContext);

        $method = new \ReflectionMethod(ApprovalManagement::class, 'resolveActor');
        return $method->invoke($management);
    }

    public function testIntegrationUserTypeResolvesToIntegrationActor(): void
    {
        $actor = $this->resolveActor($this->userContext(UserContextInterface::USER_TYPE_INTEGRATION, 9));
        $this->assertSame(['integration', '9'], $actor);
    }

    public function testAdminUserTypeResolvesToAdminActor(): void
    {
        $actor = $this->resolveActor($this->userContext(UserContextInterface::USER_TYPE_ADMIN, 5));
        $this->assertSame(['admin', '5'], $actor);
    }

    public function testNullUserIdResolvesToEmptyActorId(): void
    {
        // Deny-safe downstream: ApprovalService's role check rejects a
        // non-numeric ('') actor id, so an unresolvable identity can never
        // hold a role.
        $actor = $this->resolveActor($this->userContext(UserContextInterface::USER_TYPE_ADMIN, null));
        $this->assertSame(['admin', ''], $actor);
    }

    public function testShimPinsTheRealUserTypeValues(): void
    {
        // The discriminator values persisted in authorization_role.user_type —
        // RoleTableAuthorization queries by them, so the shim deviating from
        // the real Magento interface would silently invalidate every
        // authorization test.
        $this->assertSame(1, UserContextInterface::USER_TYPE_INTEGRATION);
        $this->assertSame(2, UserContextInterface::USER_TYPE_ADMIN);
        $this->assertSame(3, UserContextInterface::USER_TYPE_CUSTOMER);
        $this->assertSame(4, UserContextInterface::USER_TYPE_GUEST);
    }
}
