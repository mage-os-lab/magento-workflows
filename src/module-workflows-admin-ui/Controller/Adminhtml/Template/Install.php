<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Template;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Template\LocalizedText;
use MageOS\Workflows\Model\Template\TemplateInstaller;
use MageOS\Workflows\Model\Template\TemplateInstallRequest;
use MageOS\Workflows\Model\Template\TemplateSourceInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;

/**
 * Parameter form (GET) + install POST target (06). Installs in ADMIN_CONTEXT so
 * the importer re-authorizes every action against the current admin. "Install
 * as shadow" is default-on; the gallery never enables a workflow. On success it
 * redirects to the workflow edit form with dry-run/review/enable next steps.
 */
class Install extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    /** DataPersistor key so the parameter form restores input after a failed install. */
    public const PERSISTOR_KEY = 'mageos_workflow_template_install';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly TemplateSourceInterface $templateSource,
        private readonly TemplateInstaller $templateInstaller,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly ResolverInterface $localeResolver
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $code = (string) $this->getRequest()->getParam('code');
        if ($code === '' || !$this->templateSource->has($code)) {
            $this->messageManager->addErrorMessage(__('That workflow template is not available.'));
            return $this->redirect('mageos_workflows/template/index');
        }

        if ($this->getRequest()->isPost() && $this->getRequest()->getParam('install')) {
            return $this->install($code);
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Workflows::template_index');
        $resultPage->getConfig()->getTitle()->prepend(__('Install Workflow Template'));
        return $resultPage;
    }

    private function install(string $code): ResultInterface
    {
        $post = (array) $this->getRequest()->getPostValue();
        $parameters = $this->normalizeMap($post['param'] ?? []);
        $secrets = $this->collectSecrets($parameters, $this->normalizeMap($post['secret_value'] ?? []));

        // Never enabled from the gallery: shadow (recommended, default on) or disabled.
        $status = !empty($post['install_shadow'])
            ? WorkflowInterface::STATUS_SHADOW
            : WorkflowInterface::STATUS_DISABLED;

        try {
            $result = $this->templateInstaller->install(new TemplateInstallRequest(
                $code,
                $parameters,
                $status,
                ValidationContext::MODE_ADMIN_CONTEXT,
                $this->installedBy(),
                $secrets,
                $this->locale()
            ));
        } catch (LocalizedException $e) {
            $this->dataPersistor->set(self::PERSISTOR_KEY, $post);
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirect('mageos_workflows/template/install', ['code' => $code]);
        }

        $this->dataPersistor->clear(self::PERSISTOR_KEY);
        $this->addNextStepMessages($result->getWorkflow(), $status, $result->getSecretFailures());

        return $this->redirect(
            'mageos_workflows/workflow/edit',
            ['workflow_id' => $result->getWorkflow()->getWorkflowId()]
        );
    }

    /**
     * Success screen framing (discovery §4): install lands disabled/shadow; the
     * next steps are Dry-run and Enable, never an automatic activation.
     *
     * @param array<string, string> $secretFailures
     */
    private function addNextStepMessages(WorkflowInterface $workflow, int $status, array $secretFailures): void
    {
        $mode = $status === WorkflowInterface::STATUS_SHADOW ? __('shadow mode') : __('disabled');
        $this->messageManager->addSuccessMessage(__(
            'Installed "%1" in %2. Next: dry-run it against a real entity, review it, then enable it.',
            $workflow->getName(),
            $mode
        ));
        if ($secretFailures !== []) {
            $this->messageManager->addErrorMessage(__(
                'The workflow was installed, but these secrets still need to be created: %1.',
                implode(', ', array_keys($secretFailures))
            ));
        }
    }

    /**
     * A secret-typed parameter's value is the secret's key name. When the form
     * also carries a value for that key (a new secret), queue it for deferred
     * creation after a successful save.
     *
     * @param array<string, string> $parameters
     * @param array<string, string> $secretValues param key => plaintext value
     * @return array<string, string> secret key name => plaintext value
     */
    private function collectSecrets(array $parameters, array $secretValues): array
    {
        $secrets = [];
        foreach ($secretValues as $paramKey => $value) {
            $value = (string) $value;
            $secretName = (string) ($parameters[$paramKey] ?? '');
            if ($value !== '' && $secretName !== '') {
                $secrets[$secretName] = $value;
            }
        }
        return $secrets;
    }

    /**
     * @param mixed $raw
     * @return array<string, string>
     */
    private function normalizeMap($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }
        return $out;
    }

    private function installedBy(): string
    {
        $user = $this->_auth->getUser();
        return $user !== null ? (string) $user->getUserName() : 'admin';
    }

    private function locale(): string
    {
        $locale = (string) $this->localeResolver->getLocale();
        return $locale !== '' ? $locale : LocalizedText::DEFAULT_LOCALE;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function redirect(string $path, array $params = []): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath($path, $params);
    }
}
