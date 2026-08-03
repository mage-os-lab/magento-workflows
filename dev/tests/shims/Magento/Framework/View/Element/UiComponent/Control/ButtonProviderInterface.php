<?php

declare(strict_types=1);

namespace Magento\Framework\View\Element\UiComponent\Control;

/**
 * Standalone-runner shim: the contract a uiComponent form's <settings><buttons>
 * entry implements. Magento\Ui\Component\Control\Container renders whatever
 * getButtonData() returns (and an empty array hides the button), which is the
 * whole surface the workflow form's button providers are tested against.
 */
interface ButtonProviderInterface
{
    /**
     * @return array
     */
    public function getButtonData();
}
