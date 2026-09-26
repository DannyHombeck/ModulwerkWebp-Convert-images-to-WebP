<?php declare(strict_types=1);

namespace ModulwerkWebp\ScheduledTask;

use ModulwerkWebp\Service\ConversionService;
use ModulwerkWebp\Service\ScheduledTaskConfigurator;
use ModulwerkWebp\Service\WebpCacheService;
use ModulwerkWebp\Service\WebpConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Wandelt je Lauf eine Charge offener Bilder um und räumt anschließend
 * verwaiste WebP-Dateien auf. Anschließend wird der HTTP-Cache geleert,
 * sofern neue WebP-Dateien entstanden sind.
 */
#[AsMessageHandler(handles: ConvertTask::class)]
class ConvertTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ConversionService $conversionService,
        private readonly WebpConfig $config,
        private readonly WebpCacheService $cache,
        private readonly ScheduledTaskConfigurator $taskConfigurator
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        // Abstand an die Einstellungen angleichen (wirkt ab dem nächsten Termin)
        try {
            $this->taskConfigurator->syncInterval();
        } catch (\Throwable) {
            // nicht kritisch
        }

        if (!$this->config->scheduledTaskActive()) {
            return;
        }

        $daily = $this->config->taskMode() === 'daily';

        if ($daily && !$this->taskConfigurator->isDailyRunDue()) {
            return;
        }

        $longInterval = $daily || $this->config->taskIntervalMinutes() >= 60;

        if ($longInterval) {
            /*
             * Bei großem Abstand (ab einer Stunde oder einmal täglich)
             * alles abarbeiten, was offen ist – sonst bliebe ein Rückstand
             * bis zum nächsten Lauf liegen. Höchstens eine Viertelstunde
             * am Stück.
             */
            $started = microtime(true);

            do {
                $result = $this->conversionService->processPending($this->config->batchSize(), 60);
            } while ($result['processedMedia'] > 0 && $result['pending'] > 0 && (microtime(true) - $started) < 900);
        } else {
            $this->conversionService->processPending($this->config->batchSize(), 50);
        }

        $this->conversionService->cleanup();

        if ($daily) {
            $this->taskConfigurator->markDailyRun();
        }

        // Nur wenn sich etwas geändert hat
        $this->cache->flush();
    }
}
