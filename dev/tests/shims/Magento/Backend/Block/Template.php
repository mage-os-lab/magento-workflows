<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Backend\Block;

/**
 * Standalone-runner shim: the base admin template block. Only the class needs
 * to exist so subclasses (e.g. the canvas Mount block) can be defined — the
 * standalone tests never render a layout through it, they instantiate an
 * anonymous subclass with a no-op constructor, override the few inherited
 * helpers the block under test calls (getRequest/getUrl/getFormKey), and
 * inject the block's own promoted constructor dependencies by reflection
 * (mirroring the Backend\App\Action shim's posture for admin controllers).
 */
class Template
{
}
