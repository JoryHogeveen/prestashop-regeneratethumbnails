<?php

declare(strict_types=1);

namespace PrestaShop\Module\Regeneratethumbnails\Command;

use Employee;
use InvalidArgumentException;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\ImageSettings\Command\RegenerateThumbnailsCommand;
use PrestaShopBundle\Entity\ImageType;
use PrestaShopBundle\Entity\Repository\ImageTypeRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class RegenerateThumbnailsConsoleCommand extends Command
{
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

    private CommandBusInterface $commandBus;
    private LegacyContext $legacyContext;
    private ImageTypeRepository $imageTypeRepository;

    public function __construct(
        CommandBusInterface $commandBus,
        LegacyContext $legacyContext,
        ImageTypeRepository $imageTypeRepository
    ) {
        parent::__construct();
        $this->commandBus = $commandBus;
        $this->legacyContext = $legacyContext;
        $this->imageTypeRepository = $imageTypeRepository;
    }

    protected function configure(): void
    {
        $this
            ->setName('prestashop:image:thumbnails:regenerate')
            ->setDescription('Regenerate image thumbnails')
            ->addOption(
                'rease_previous',
                null,
                InputOption::VALUE_NONE,
                'Delete previously generated thumbnails before regenerating (only for selected scope/type).'
            )
            ->addOption(
                'erase_previous',
                null,
                InputOption::VALUE_NONE,
                'Alias of --rease_previous.'
            )
            ->addOption(
                'image_type',
                null,
                InputOption::VALUE_OPTIONAL,
                'Image type ID or name (for example: home_default).'
            )
            ->addOption(
                'image_scope',
                null,
                InputOption::VALUE_OPTIONAL,
                'Image scope: all, product(s), category(ies), manufacturer(s)/brand(s), supplier(s), store(s).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->initContext();

            $rawImageScope = $this->normalizeNullableString($input->getOption('image_scope'));
            $rawImageType = $this->normalizeNullableString($input->getOption('image_type'));
            $erasePrevious = (bool) $input->getOption('rease_previous') || (bool) $input->getOption('erase_previous');

            if (null === $rawImageScope && null === $rawImageType) {
                if (!$input->isInteractive()) {
                    $io->error('No filters were provided. Use --image_scope and/or --image_type, or run in interactive mode to confirm full regeneration.');

                    return self::INVALID;
                }

                if (!$io->confirm('No image_scope or image_type was provided. Regenerate all image thumbnails?', false)) {
                    $io->warning('Aborted by user.');

                    return self::SUCCESS;
                }
            }

            $imageScope = null !== $rawImageScope ? $this->normalizeScope($rawImageScope) : 'all';
            $imageType = null !== $rawImageType ? $this->resolveImageType($rawImageType) : null;

            if ('all' !== $imageScope && null !== $imageType && !$this->imageTypeSupportsScope($imageType, $imageScope)) {
                throw new InvalidArgumentException(sprintf(
                    'Image type "%s" is not linked to scope "%s".',
                    $imageType->getName(),
                    $imageScope
                ));
            }

            $this->commandBus->handle(new RegenerateThumbnailsCommand(
                $imageScope,
                null !== $imageType ? $imageType->getId() : 0,
                $erasePrevious
            ));

            $io->success(sprintf(
                'Thumbnail regeneration started for scope "%s"%s%s.',
                $imageScope,
                null !== $imageType ? sprintf(' and image type "%s"', $imageType->getName()) : '',
                $erasePrevious ? ' (previous thumbnails were removed first)' : ''
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function initContext(): void
    {
        if (!$this->legacyContext->getContext()->employee) {
            $this->legacyContext->getContext()->employee = new Employee(42);
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return '' === $normalized ? null : $normalized;
    }

    private function normalizeScope(string $scope): string
    {
        $normalizedScope = strtolower(trim($scope));

        if (!isset($this->scopeAliases[$normalizedScope])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid image_scope "%s". Allowed values: %s',
                $scope,
                implode(', ', array_keys($this->scopeAliases))
            ));
        }

        return $this->scopeAliases[$normalizedScope];
    }

    private function resolveImageType(string $imageType): ImageType
    {
        if (ctype_digit($imageType)) {
            $resolvedImageType = $this->imageTypeRepository->find((int) $imageType);
        } else {
            $resolvedImageType = $this->imageTypeRepository->getByName($imageType);
        }

        if (!$resolvedImageType instanceof ImageType) {
            throw new InvalidArgumentException(sprintf('Image type "%s" was not found.', $imageType));
        }

        return $resolvedImageType;
    }

    private function imageTypeSupportsScope(ImageType $imageType, string $scope): bool
    {
        return match ($scope) {
            'products' => $imageType->isProducts(),
            'categories' => $imageType->isCategories(),
            'manufacturers' => $imageType->isManufacturers(),
            'suppliers' => $imageType->isSuppliers(),
            'stores' => $imageType->isStores(),
            default => true,
        };
    }
}

