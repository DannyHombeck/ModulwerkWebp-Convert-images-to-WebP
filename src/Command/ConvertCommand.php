<?php declare(strict_types=1);

namespace ModulwerkWebp\Command;

use ModulwerkWebp\Service\ConversionService;
use ModulwerkWebp\Service\WebpCacheService;
use ModulwerkWebp\Service\WebpConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'modulwerk:webp:convert', description: 'Wandelt offene JPG/PNG/GIF/BMP/TIFF-Bilder in WebP um')]
class ConvertCommand extends Command
{
    public function __construct(
        private readonly ConversionService $conversionService,
        private readonly WebpConfig $config,
        private readonly WebpCacheService $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Höchstens so viele Medien umwandeln (0 = alle)', '0')
            ->addOption('media-id', 'm', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur dieses Medium (neu) umwandeln')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Alles verwerfen und komplett neu erzeugen')
            ->addOption('retry-errors', null, InputOption::VALUE_NONE, 'Fehlgeschlagene Dateien erneut versuchen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $mediaIds */
        $mediaIds = $input->getOption('media-id');

        if ($mediaIds !== []) {
            foreach ($mediaIds as $mediaId) {
                $stats = $this->conversionService->convertMedia(strtolower($mediaId), true);
                $io->writeln(sprintf('%s: %d umgewandelt, %d übersprungen, %d Fehler', $mediaId, $stats['converted'], $stats['skipped'], $stats['errors']));
            }

            return self::SUCCESS;
        }

        if ($input->getOption('force')) {
            $this->conversionService->clearAll();
            $io->note('Alle vorhandenen WebP-Dateien wurden verworfen.');
        } elseif ($input->getOption('retry-errors')) {
            $io->note(sprintf('%d fehlgeschlagene Einträge zurückgesetzt.', $this->conversionService->resetErrors()));
        }

        $limit = max(0, (int) $input->getOption('limit'));
        $pending = $this->conversionService->countPendingMedia();
        $total = $limit > 0 ? min($limit, $pending) : $pending;

        if ($total === 0) {
            $io->success('Keine offenen Bilder.');

            return self::SUCCESS;
        }

        $batch = $this->config->batchSize();
        $done = 0;
        $sum = ['converted' => 0, 'skipped' => 0, 'errors' => 0];

        $io->progressStart($total);

        while ($done < $total) {
            $result = $this->conversionService->processPending(min($batch, $total - $done), 0);

            if ($result['engine'] === null) {
                $io->progressFinish();
                $io->error('Weder GD noch Imagick können auf diesem Server WebP schreiben.');

                return self::FAILURE;
            }

            if ($result['processedMedia'] === 0) {
                break;
            }

            $done += $result['processedMedia'];
            $sum['converted'] += $result['converted'];
            $sum['skipped'] += $result['skipped'];
            $sum['errors'] += $result['errors'];
            $io->progressAdvance($result['processedMedia']);
        }

        $io->progressFinish();

        if ($this->config->mediaLibrary()) {
            while ($this->conversionService->syncLibrary(200) > 0) {
                // Einträge im Medienordner "Modulwerk WebP-Konverter" nachtragen
            }
        }

        $io->success(sprintf(
            '%d Medien verarbeitet: %d Dateien umgewandelt, %d übersprungen, %d Fehler. %s',
            $done,
            $sum['converted'],
            $sum['skipped'],
            $sum['errors'],
            $this->cache->flush() ? 'HTTP-Cache wurde geleert.' : ($this->cache->isEnabled() ? '' : 'Jetzt bin/console cache:clear ausführen.')
        ));

        return self::SUCCESS;
    }
}
