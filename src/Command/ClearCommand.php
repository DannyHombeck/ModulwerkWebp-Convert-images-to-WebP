<?php declare(strict_types=1);

namespace ModulwerkWebp\Command;

use ModulwerkWebp\Service\ConversionService;
use ModulwerkWebp\Service\WebpCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'modulwerk:webp:clear', description: 'Löscht WebP-Dateien (alle oder nur verwaiste)')]
class ClearCommand extends Command
{
    public function __construct(
        private readonly ConversionService $conversionService,
        private readonly WebpCacheService $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('orphaned', 'o', InputOption::VALUE_NONE, 'Nur WebP-Dateien löschen, deren Original nicht mehr existiert');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('orphaned')) {
            $io->success(sprintf('%d verwaiste Einträge entfernt.', $this->conversionService->cleanup()));

            return self::SUCCESS;
        }

        $this->conversionService->clearAll();
        $io->success('Alle WebP-Dateien gelöscht. ' . ($this->cache->flush() ? 'HTTP-Cache wurde geleert.' : 'Jetzt bin/console cache:clear ausführen.'));

        return self::SUCCESS;
    }
}
