<?php
/**
 * Install configuration for the Magento integration-test framework
 * (docs/20-integration-test-plan.md §2.2). The `integration-test-extension`
 * CI job copies this file to <magento>/dev/tests/integration/etc/
 * install-config-mysql.php; credentials match the job's service containers.
 *
 * `amqp-*` keys are DELIBERATELY omitted: with no amqp connection configured,
 * the message-queue framework falls back to the `db` transport, so suites
 * drive ExecuteConsumer/ResumeConsumer in-process with no broker service —
 * the same deployment-decides posture as etc/queue_consumer.xml.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

return [
    'db-host' => '127.0.0.1',
    'db-user' => 'root',
    'db-password' => 'password',
    'db-name' => 'magento_integration_tests',
    'db-prefix' => '',
    'backend-frontname' => 'backend',
    'search-engine' => 'opensearch',
    'opensearch-host' => '127.0.0.1',
    'opensearch-port' => '9200',
    'admin-user' => \Magento\TestFramework\Bootstrap::ADMIN_NAME,
    'admin-password' => \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD,
    'admin-email' => \Magento\TestFramework\Bootstrap::ADMIN_EMAIL,
    'admin-firstname' => \Magento\TestFramework\Bootstrap::ADMIN_FIRSTNAME,
    'admin-lastname' => \Magento\TestFramework\Bootstrap::ADMIN_LASTNAME,
];
