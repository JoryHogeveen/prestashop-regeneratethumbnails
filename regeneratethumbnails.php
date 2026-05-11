<?php

declare(strict_types=1);

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\Module\Regeneratethumbnails\Service\ThumbnailBatchRegenerator;
use PrestaShopBundle\Entity\Repository\ImageTypeRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Regeneratethumbnails extends Module
{
    public function __construct()
    {
        $this->name = 'regeneratethumbnails';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Keraweb';
        $this->need_instance = 0;
        $this->bootstrap = false;

        parent::__construct();

        $this->displayName = $this->trans('RegenerateThumbnails', [], 'Modules.Regeneratethumbnails.Admin');
        $this->description = $this->trans('Provides an external CLI script to regenerate thumbnails without core overrides.', [], 'Modules.Regeneratethumbnails.Admin');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent(): string
    {
        if ((bool) Tools::getValue('ajax')) {
            $this->processAjaxRequest();
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin/batch-regenerate.js');

        $this->context->smarty->assign([
            'ajax_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
            'image_types' => $this->getImageTypes(),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getImageTypes(): array
    {
        $container = SymfonyContainer::getInstance();
        /** @var ImageTypeRepository $imageTypeRepository */
        $imageTypeRepository = $container->get(ImageTypeRepository::class);
        $imageTypes = $imageTypeRepository->findBy([], ['name' => 'ASC']);

        $choices = [];
        foreach ($imageTypes as $imageType) {
            $choices[] = [
                'id' => (int) $imageType->getId(),
                'name' => (string) $imageType->getName(),
            ];
        }

        return $choices;
    }

    private function processAjaxRequest(): void
    {
        header('Content-Type: application/json');

        try {
            if (!$this->context->employee || !$this->context->employee->id) {
                throw new Exception('Employee context is missing.');
            }

            $action = (string) Tools::getValue('action');
            if (!in_array($action, ['initBatchRegeneration', 'processBatchRegeneration'], true)) {
                throw new Exception('Invalid AJAX action.');
            }

            $employeeId = (int) $this->context->employee->id;
            $service = $this->getBatchRegeneratorService();

            if ('initBatchRegeneration' === $action) {
                $result = $service->initJob(
                    $employeeId,
                    (string) Tools::getValue('image_scope', 'all'),
                    $this->normalizeImageTypeInput((string) Tools::getValue('image_type', '0')),
                    (bool) Tools::getValue('rease_previous') || (bool) Tools::getValue('erase_previous'),
                    (int) Tools::getValue('batch_size', 50)
                );
            } else {
                $result = $service->processNextBatch(
                    $employeeId,
                    (string) Tools::getValue('job_id')
                );
            }

            die(json_encode([
                'success' => true,
                'data' => $result,
            ]));
        } catch (Throwable $e) {
            die(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function normalizeImageTypeInput(string $imageType): ?string
    {
        $imageType = trim($imageType);

        if ('' === $imageType || '0' === $imageType) {
            return null;
        }

        return $imageType;
    }

    private function getBatchRegeneratorService(): ThumbnailBatchRegenerator
    {
        return SymfonyContainer::getInstance()->get(ThumbnailBatchRegenerator::class);
    }
}
