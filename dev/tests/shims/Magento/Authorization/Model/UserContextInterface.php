<?php
declare(strict_types=1);

namespace Magento\Authorization\Model;

/**
 * Standalone-runner shim for Magento\Authorization\Model\UserContextInterface.
 * The USER_TYPE_* values mirror the real interface EXACTLY — they are
 * discriminator values persisted in the authorization_role table's user_type
 * column, so a shim that deviated would let tests pass against values the real
 * schema never contains (the Stage 3 review bug this shim exists to prevent).
 */
interface UserContextInterface
{
    public const USER_TYPE_INTEGRATION = 1;
    public const USER_TYPE_ADMIN = 2;
    public const USER_TYPE_CUSTOMER = 3;
    public const USER_TYPE_GUEST = 4;

    /**
     * @return int|null
     */
    public function getUserId();

    /**
     * @return int|null
     */
    public function getUserType();
}
