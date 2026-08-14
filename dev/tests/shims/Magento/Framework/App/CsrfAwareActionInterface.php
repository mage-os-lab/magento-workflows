<?php

declare(strict_types=1);

namespace Magento\Framework\App;

/**
 * Standalone-runner shim: the interface a controller implements to take over
 * CSRF validation from the framework (real Magento declares
 * createCsrfValidationException() and validateForCsrf()).
 *
 * Empty by design — no controller in this repo implements it, and that is
 * exactly what WorkflowActionMethodContractTest asserts: an admin action must
 * not opt out of the form-key check the POST dispatch performs. The shim exists
 * so that assertion names a real, loadable type rather than a string nobody
 * would notice going stale.
 */
interface CsrfAwareActionInterface
{
}
