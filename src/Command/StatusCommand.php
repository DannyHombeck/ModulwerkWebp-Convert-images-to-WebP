<?php declare(strict_types=1);

namespace ModulwerkWebp\Command;

use ModulwerkWebp\Service\ConversionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'modulwerk:webp:status', description: 'Zeigt den Stand der WebP-Umwandlung')]
class StatusCommand extends Command
{
    public function __construct(private readonly ConversionService $conversionService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $s = $this->conversionService->getStatus();
        $saved = $s['bytesOriginal'] - $s['bytesWebp'];

        $io->table(['', ''], [
            ['Bilder (JPG/PNG/GIF/BMP/TIFF)', $s['mediaTotal']],
            ['davon offen', $s['mediaPending']],
            ['WebP-Dateien', $s['filesConverted']],
            ['übersprungen', $s['filesSkipped']],
            ['Fehler', $s['filesError']],
            ['Original', $this->format($s['bytesOriginal'])],
            ['WebP', $this->format($s['bytesWebp'])],
            ['Ersparnis', $this->format($saved) . ($s['bytesOriginal'] > 0 ? sprintf(' (%.1f %%)', $saved / $s['bytesOriginal'] * 100) : '')],
            ['Bibliothek', $s['engine'] ?? 'keine – WebP nicht möglich'],
            ['GD / Imagick', ($s['gdAvailable'] ? 'ja' : 'nein') . ' / ' . ($s['imagickAvailable'] ? 'ja' : 'nein')],
            ['memory_limit', $s['memoryLimit']],
        ]);

        return self::SUCCESS;
    }

    private function format(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
    }
}
