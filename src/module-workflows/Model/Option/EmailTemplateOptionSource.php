<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Option;

use Magento\Email\Model\ResourceModel\Template\CollectionFactory;

/**
 * Search-typed option source: saved (DB) email templates, template_id => code
 * (F6). Referenced with min_chars: 2. Only the merchant-authored templates in
 * email_template are listed; the file-based config templates are not enumerated
 * here (a natural follow-up if needed).
 */
class EmailTemplateOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly CollectionFactory $templateCollectionFactory
    ) {
    }

    public function getCode(): string
    {
        return 'email_templates';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $collection = $this->templateCollectionFactory->create();
        $options = [];
        foreach ($collection as $template) {
            $options[] = [
                'value' => (string) $template->getData('template_id'),
                'label' => (string) $template->getData('template_code'),
            ];
        }
        return $options;
    }
}
