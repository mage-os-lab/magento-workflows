<?php
declare(strict_types=1);

namespace Magento\Quote\Model;

/**
 * Standalone-runner shim for Magento\Quote\Model\Quote — only the quote-MODEL
 * surface the dry-run recent-quote provider reads behind its `instanceof Quote`
 * guard (customer email and grand total live on the model, not on
 * Magento\Quote\Api\Data\CartInterface). Subclassed by the sales pack's test
 * fakes, which override the accessors they exercise; real Magento installs load
 * the real class instead.
 */
class Quote
{
    /**
     * @return mixed
     */
    public function getId()
    {
        return null;
    }

    /**
     * @return mixed
     */
    public function getCustomerEmail()
    {
        return null;
    }

    /**
     * @return mixed
     */
    public function getGrandTotal()
    {
        return null;
    }
}
