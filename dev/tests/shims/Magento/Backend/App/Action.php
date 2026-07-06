<?php

declare(strict_types=1);

namespace Magento\Backend\App;

/**
 * Standalone-runner shim: the base admin controller. Only the class needs to
 * exist so subclasses (e.g. the admin Save controller) can be defined and
 * reflected — the standalone tests never route a request through it, they
 * instantiate the subclass without its constructor and exercise pure helpers.
 */
abstract class Action
{
}
