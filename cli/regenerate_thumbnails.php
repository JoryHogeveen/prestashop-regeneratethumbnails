#!/usr/bin/env php
<?php

declare(strict_types=1);

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Domain\ImageSettings\Command\RegenerateThumbnailsCommand;
use PrestaShop\PrestaShop\Core\Domain\ImageSettings\Exception\ImageSettingsException;
use PrestaShopBundle\Entity\ImageType;
use PrestaShopBundle\Entity\Repository\ImageTypeRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$options = getopt('', [
    'rease_previous',
    'erase_previous',
    'image_type::',
    'image_scope::',
    'help',
]);

if (isset($options['help'])) {
    printHelp();
    exit(0);
}

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.inc.php';
require_once $projectRoot . '/init.php';

$imageScopeInput = normalizeNullableString($options['image_scope'] ?? null);
$imageTypeInput = normalizeNullableString($options['image_type'] ?? null);
$erasePrevious = isset($options['rease_previous']) || isset($options['erase_previous']);

if (null === $imageScopeInput && null === $imageTypeInput) {
    if (!isInteractiveStdin()) {
        fwrite(STDERR, "No image_scope or image_type provided. Run interactively to confirm regenerating all images, or pass one of these options.\n");
        exit(2);
    }

    if (!confirm('No image_scope or image_type was provided. Regenerate ALL thumbnails?', false)) {
        fwrite(STDOUT, "Aborted.\n");
        exit(0);
    }
}

$scopeAliases = [
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

$normalizedScope = 'all';
if (null !== $imageScopeInput) {
    $scopeKey = strtolower(trim($imageScopeInput));
    if (!isset($scopeAliases[$scopeKey])) {
        fwrite(STDERR, sprintf(
            "Invalid --image_scope \"%s\". Allowed values: %s\n",
            $imageScopeInput,
            implode(', ', array_keys($scopeAliases))
        ));
        exit(2);
    }

    $normalizedScope = $scopeAliases[$scopeKey];
}

try {
    $context = Context::getContext();
    if (empty($context->employee)) {
        $context->employee = new Employee(42);
    }

    $container = SymfonyContainer::getInstance();

    /** @var ImageTypeRepository $imageTypeRepository */
    $imageTypeRepository = $container->get(ImageTypeRepository::class);

    $imageType = null;
    if (null !== $imageTypeInput) {
        if (ctype_digit($imageTypeInput)) {
            $imageType = $imageTypeRepository->find((int) $imageTypeInput);
        } else {
            $imageType = $imageTypeRepository->getByName($imageTypeInput);
        }

        if (!$imageType instanceof ImageType) {
            fwrite(STDERR, sprintf("Image type \"%s\" was not found.\n", $imageTypeInput));
            exit(2);
        }

        if ('all' !== $normalizedScope && !imageTypeSupportsScope($imageType, $normalizedScope)) {
            fwrite(STDERR, sprintf(
                "Image type \"%s\" is not linked to scope \"%s\".\n",
                $imageType->getName(),
                $normalizedScope
            ));
            exit(2);
        }
    }

    $imageTypeId = $imageType instanceof ImageType ? $imageType->getId() : 0;

    $commandBus = $container->get('prestashop.core.command_bus');
    $commandBus->handle(new RegenerateThumbnailsCommand($normalizedScope, $imageTypeId, $erasePrevious));

    fwrite(STDOUT, sprintf(
        "Done. Regenerated thumbnails for scope=\"%s\"%s%s\n",
        $normalizedScope,
        $imageType instanceof ImageType ? ', image_type="' . $imageType->getName() . '"' : ', image_type="all"',
        $erasePrevious ? ', erase_previous=yes' : ', erase_previous=no'
    ));

    exit(0);
} catch (ImageSettingsException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("Unhandled error: %s\n", $e->getMessage()));
    exit(1);
}

function printHelp(): void
{
    $help = <<<TXT
PrestaShop thumbnail regeneration CLI (module: regeneratethumbnails)

Usage:
  php modules/regeneratethumbnails/cli/regenerate_thumbnails.php [options]

Options:
  --rease_previous      Delete existing generated thumbnails for the selected scope/type before regenerating
  --erase_previous      Alias for --rease_previous
  --image_type=<value>  Image type id or name (example: home_default)
  --image_scope=<value> all|product(s)|category(ies)|manufacturer(s)|brand(s)|supplier(s)|store(s)
  --help                Show this help

If both --image_type and --image_scope are missing, the script asks for confirmation before regenerating all images.
TXT;

    fwrite(STDOUT, $help . "\n");
}

function normalizeNullableString($value): ?string
{
    $normalized = trim((string) $value);

    return $normalized === '' ? null : $normalized;
}

function isInteractiveStdin(): bool
{
    if (defined('STDIN') && function_exists('stream_isatty')) {
        return stream_isatty(STDIN);
    }

    if (defined('STDIN') && function_exists('posix_isatty')) {
        return posix_isatty(STDIN);
    }

    return false;
}

function confirm(string $question, bool $defaultNo = true): bool
{
    $suffix = $defaultNo ? ' [y/N]: ' : ' [Y/n]: ';
    fwrite(STDOUT, $question . $suffix);

    $answer = trim((string) fgets(STDIN));
    if ($answer === '') {
        return !$defaultNo;
    }

    $answer = strtolower($answer);

    return in_array($answer, ['y', 'yes'], true);
}

function imageTypeSupportsScope(ImageType $imageType, string $scope): bool
{
    switch ($scope) {
        case 'products':
            return $imageType->isProducts();
        case 'categories':
            return $imageType->isCategories();
        case 'manufacturers':
            return $imageType->isManufacturers();
        case 'suppliers':
            return $imageType->isSuppliers();
        case 'stores':
            return $imageType->isStores();
        default:
            return true;
    }
}


