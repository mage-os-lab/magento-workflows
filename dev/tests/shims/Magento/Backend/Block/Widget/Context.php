<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Backend\Block\Widget;

/**
 * Standalone-runner shim: the admin widget block context. Only the three
 * accessors the workflow form's button providers (GenericButton and its
 * subclasses) reach for are declared — request, URL builder, authorization.
 * The standalone tests subclass this anonymously and return their own doubles;
 * the real context additionally carries the whole layout/session stack, none of
 * which a button provider touches.
 */
class Context
{
    /**
     * @return \Magento\Framework\App\RequestInterface|null
     */
    public function getRequest()
    {
        return null;
    }

    /**
     * @return \Magento\Framework\UrlInterface|null
     */
    public function getUrlBuilder()
    {
        return null;
    }

    /**
     * @return \Magento\Framework\AuthorizationInterface|null
     */
    public function getAuthorization()
    {
        return null;
    }
}
