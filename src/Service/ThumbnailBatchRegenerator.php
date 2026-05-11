<?php

declare(strict_types=1);

namespace PrestaShop\Module\Regeneratethumbnails\Service;

use Configuration;
use Db;
use Image;
use ImageManager;
use InvalidArgumentException;
use PrestaShop\PrestaShop\Adapter\ImageThumbnailsRegenerator;
use PrestaShop\PrestaShop\Core\Image\ImageFormatConfiguration;
use PrestaShopBundle\Entity\ImageType;
use PrestaShopBundle\Entity\Repository\ImageTypeRepository;

class ThumbnailBatchRegenerator
{
    private const JOB_CONFIG_KEY_PREFIX = 'REGENERATETHUMBNAILS_JOB_';

    /** @var array<string, array{name: string, dir: string, is_product: bool}> */
    private array $scopeConfig = [
        'categories' => ['name' => 'Categories', 'dir' => _PS_CAT_IMG_DIR_, 'is_product' => false],
        'manufacturers' => ['name' => 'Brands', 'dir' => _PS_MANU_IMG_DIR_, 'is_product' => false],
        'suppliers' => ['name' => 'Suppliers', 'dir' => _PS_SUPP_IMG_DIR_, 'is_product' => false],
        'products' => ['name' => 'Products', 'dir' => _PS_PRODUCT_IMG_DIR_, 'is_product' => true],
        'stores' => ['name' => 'Stores', 'dir' => _PS_STORE_IMG_DIR_, 'is_product' => false],
    ];

    /** @var array<string, string> */
    private array $scopeAliases = [
        'all' => 'all',
        'product' => 'products',
        'products' => 'products',
        'category' => 'categories',
        'categories' => 'categories',
        'manufacturer' => 'manufacturers',
        'manufacturers' => 'manufacturers',
        'brand' => 'manufacturers',
        'brands' => 'manufacturers',
        'supplier' => 'suppliers',
        'suppliers' => 'suppliers',
        'store' => 'stores',
        'stores' => 'stores',
    ];

    private ImageTypeRepository $imageTypeRepository;
    private ImageThumbnailsRegenerator $imageThumbnailsRegenerator;
    private ImageFormatConfiguration $imageFormatConfiguration;

    public function __construct(
        ImageTypeRepository $imageTypeRepository,
        ImageThumbnailsRegenerator $imageThumbnailsRegenerator,
        ImageFormatConfiguration $imageFormatConfiguration
    ) {
        $this->imageTypeRepository = $imageTypeRepository;
        $this->imageThumbnailsRegenerator = $imageThumbnailsRegenerator;
        $this->imageFormatConfiguration = $imageFormatConfiguration;
    }

    /**
     * @return array<string, mixed>
     */
    public function initJob(int $employeeId, string $scopeInput, ?string $imageTypeInput, bool $erasePrevious, int $batchSize): array
    {
        $scope = $this->normalizeScope($scopeInput);
        $imageTypeId = $this->resolveImageTypeId($imageTypeInput);
        $batchSize = max(1, min(500, $batchSize));

        $scopes = 'all' === $scope ? array_keys($this->scopeConfig) : [$scope];
        $scopeStates = [];
        $totalItems = 0;

        foreach ($scopes as $scopeName) {
            $types = $this->getImageTypesForScope($scopeName, $imageTypeId);
            if (empty($types)) {
                if ($imageTypeId > 0) {
                    throw new InvalidArgumentException(sprintf('Image type is not linked to scope "%s".', $scopeName));
                }

                continue;
            }

            $total = $this->countScopeItems($scopeName);
            $totalItems += $total;

            $scopeStates[] = [
                'scope' => $scopeName,
                'offset' => 0,
                'total' => $total,
                'erased' => false,
                'done' => (0 === $total),
            ];
        }

        if (empty($scopeStates)) {
            throw new InvalidArgumentException('No image types found for the selected scope/type filter.');
        }

        $job = [
            'id' => bin2hex(random_bytes(12)),
            'employee_id' => $employeeId,
            'scope' => $scope,
            'image_type_id' => $imageTypeId,
            'erase_previous' => $erasePrevious,
            'batch_size' => $batchSize,
            'scopes' => $scopeStates,
            'current_scope_index' => 0,
            'processed' => 0,
            'total' => $totalItems,
            'complete' => (0 === $totalItems),
        ];

        $this->saveJob($employeeId, $job);

        return $this->buildProgressPayload($job, null, 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function processNextBatch(int $employeeId, string $jobId): array
    {
        $job = $this->getJob($employeeId);
        if (null === $job || $job['id'] !== $jobId) {
            throw new InvalidArgumentException('Batch job not found or expired.');
        }

        if (!empty($job['complete'])) {
            return $this->buildProgressPayload($job, null, 0);
        }

        $processedThisStep = 0;
        $currentScope = null;

        while (isset($job['scopes'][$job['current_scope_index']]) && !empty($job['scopes'][$job['current_scope_index']]['done'])) {
            $job['current_scope_index']++;
        }

        if (!isset($job['scopes'][$job['current_scope_index']])) {
            $job['complete'] = true;
            $this->saveJob($employeeId, $job);

            return $this->buildProgressPayload($job, null, 0);
        }

        $scopeIndex = (int) $job['current_scope_index'];
        $scopeState = $job['scopes'][$scopeIndex];
        $currentScope = $scopeState['scope'];

        $types = $this->getImageTypesForScope($currentScope, (int) $job['image_type_id']);

        if (!empty($job['erase_previous']) && empty($scopeState['erased'])) {
            $scopeMeta = $this->scopeConfig[$currentScope];
            $this->imageThumbnailsRegenerator->deletePreviousImages($scopeMeta['dir'], $types, $scopeMeta['is_product']);
            $scopeState['erased'] = true;
        }

        $processedThisStep = $this->processScopeChunk(
            $scopeState['scope'],
            (int) $scopeState['offset'],
            (int) $job['batch_size'],
            $types
        );

        $scopeState['offset'] = min((int) $scopeState['offset'] + $processedThisStep, (int) $scopeState['total']);

        if ($scopeState['offset'] >= $scopeState['total'] || 0 === $processedThisStep) {
            $scopeState['done'] = true;
            $job['current_scope_index'] = $scopeIndex + 1;
        }

        $job['scopes'][$scopeIndex] = $scopeState;
        $job['processed'] = $this->countProcessedItems($job);

        if ($job['processed'] >= $job['total']) {
            $job['complete'] = true;
        }

        $this->saveJob($employeeId, $job);

        return $this->buildProgressPayload($job, $currentScope, $processedThisStep);
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function buildProgressPayload(array $job, ?string $currentScope, int $processedThisStep): array
    {
        $total = (int) ($job['total'] ?? 0);
        $processed = (int) ($job['processed'] ?? 0);
        $progress = 0.0;
        if ($total > 0) {
            $progress = round(($processed / $total) * 100, 2);
        }

        return [
            'job_id' => $job['id'],
            'complete' => (bool) ($job['complete'] ?? false),
            'processed' => $processed,
            'total' => $total,
            'processed_step' => $processedThisStep,
            'progress' => $progress,
            'current_scope' => $currentScope,
        ];
    }

    /**
     * @param array<string, mixed> $job
     */
    private function saveJob(int $employeeId, array $job): void
    {
        Configuration::updateValue($this->getJobConfigKey($employeeId), json_encode($job));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJob(int $employeeId): ?array
    {
        $rawJob = Configuration::get($this->getJobConfigKey($employeeId));
        if (!$rawJob) {
            return null;
        }

        $job = json_decode((string) $rawJob, true);
        if (!is_array($job)) {
            return null;
        }

        return $job;
    }

    private function getJobConfigKey(int $employeeId): string
    {
        return self::JOB_CONFIG_KEY_PREFIX . $employeeId;
    }

    private function normalizeScope(string $scopeInput): string
    {
        $scope = strtolower(trim($scopeInput));
        if (!isset($this->scopeAliases[$scope])) {
            throw new InvalidArgumentException(sprintf('Invalid image scope "%s".', $scopeInput));
        }

        return $this->scopeAliases[$scope];
    }

    private function resolveImageTypeId(?string $imageTypeInput): int
    {
        if (null === $imageTypeInput || '' === trim($imageTypeInput) || '0' === trim($imageTypeInput)) {
            return 0;
        }

        $imageTypeInput = trim($imageTypeInput);
        if (ctype_digit($imageTypeInput)) {
            $imageType = $this->imageTypeRepository->find((int) $imageTypeInput);
        } else {
            $imageType = $this->imageTypeRepository->getByName($imageTypeInput);
        }

        if (!$imageType instanceof ImageType) {
            throw new InvalidArgumentException(sprintf('Image type "%s" was not found.', $imageTypeInput));
        }

        return (int) $imageType->getId();
    }

    /**
     * @return ImageType[]
     */
    private function getImageTypesForScope(string $scope, int $imageTypeId): array
    {
        $criteria = [$scope => 1];
        if ($imageTypeId > 0) {
            $criteria['id'] = $imageTypeId;
        }

        return $this->imageTypeRepository->findBy($criteria);
    }

    private function countScopeItems(string $scope): int
    {
        $scopeMeta = $this->scopeConfig[$scope];
        if ($scopeMeta['is_product']) {
            $count = Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image`');

            return (int) $count;
        }

        return count($this->listScopeOriginals($scopeMeta['dir']));
    }

    /**
     * @param ImageType[] $types
     */
    private function processScopeChunk(string $scope, int $offset, int $limit, array $types): int
    {
        $scopeMeta = $this->scopeConfig[$scope];
        if ($scopeMeta['is_product']) {
            return $this->processProductChunk($offset, $limit, $types);
        }

        return $this->processRegularChunk($scopeMeta['dir'], $offset, $limit, $types);
    }

    /**
     * @param ImageType[] $types
     */
    private function processProductChunk(int $offset, int $limit, array $types): int
    {
        $rows = Db::getInstance()->executeS(sprintf(
            'SELECT id_image FROM `%simage` ORDER BY id_image ASC LIMIT %d, %d',
            _DB_PREFIX_,
            $offset,
            $limit
        ));

        if (empty($rows)) {
            return 0;
        }

        $formats = $this->imageFormatConfiguration->getGenerationFormats();

        foreach ($rows as $row) {
            $imageId = (int) $row['id_image'];
            $basePath = Image::getImgFolderStatic($imageId) . $imageId;
            $sourcePath = _PS_PRODUCT_IMG_DIR_ . $basePath . '.jpg';

            if (!file_exists($sourcePath) || !filesize($sourcePath)) {
                continue;
            }

            foreach ($types as $imageType) {
                foreach ($formats as $format) {
                    $targetPath = _PS_PRODUCT_IMG_DIR_ . $basePath . '-' . $imageType->getName() . '.' . $format;
                    if (!file_exists($targetPath)) {
                        ImageManager::resize(
                            $sourcePath,
                            $targetPath,
                            (int) $imageType->getWidth(),
                            (int) $imageType->getHeight(),
                            $format
                        );
                    }
                }
            }
        }

        return count($rows);
    }

    /**
     * @param ImageType[] $types
     */
    private function processRegularChunk(string $dir, int $offset, int $limit, array $types): int
    {
        $files = $this->listScopeOriginals($dir);
        $chunk = array_slice($files, $offset, $limit);
        if (empty($chunk)) {
            return 0;
        }

        $formats = $this->imageFormatConfiguration->getGenerationFormats();

        foreach ($chunk as $filename) {
            $sourcePath = $dir . $filename;
            if (!file_exists($sourcePath) || !filesize($sourcePath)) {
                continue;
            }

            $basename = substr($filename, 0, -4);
            foreach ($types as $imageType) {
                foreach ($formats as $format) {
                    $targetPath = $dir . $basename . '-' . $imageType->getName() . '.' . $format;
                    if (!file_exists($targetPath)) {
                        ImageManager::resize(
                            $sourcePath,
                            $targetPath,
                            (int) $imageType->getWidth(),
                            (int) $imageType->getHeight(),
                            $format
                        );
                    }
                }
            }
        }

        return count($chunk);
    }

    /**
     * @return string[]
     */
    private function listScopeOriginals(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir, SCANDIR_SORT_ASCENDING);
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static function (string $filename): bool {
            return preg_match('/^[0-9]+(?:_thumb)?\.jpg$/', $filename) === 1;
        }));
    }

    /**
     * @param array<string, mixed> $job
     */
    private function countProcessedItems(array $job): int
    {
        $processed = 0;
        if (!isset($job['scopes']) || !is_array($job['scopes'])) {
            return $processed;
        }

        foreach ($job['scopes'] as $scopeState) {
            $processed += min((int) ($scopeState['offset'] ?? 0), (int) ($scopeState['total'] ?? 0));
        }

        return $processed;
    }
}

