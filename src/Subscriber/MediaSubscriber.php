<?php declare(strict_types=1);

namespace ModulwerkWebp\Subscriber;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use ModulwerkWebp\Service\ConversionService;
use ModulwerkWebp\Service\ScheduledTaskConfigurator;
use Shopware\Core\Content\Media\Event\UnusedMediaSearchEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hält die WebP-Dateien synchron zu den Medien:
 *
 *  - Medium gelöscht            -> WebP-Dateien löschen
 *  - Datei eines Mediums ersetzt -> veraltete WebP-Dateien löschen
 *  - Thumbnails neu erzeugt      -> deren WebP-Dateien löschen
 *  - Eintrag im Ordner "Modulwerk WebP-Konverter" gelöscht oder umbenannt
 *                                -> nicht mehr ausliefern
 *  - "Unbenutzte Medien löschen" -> Einträge im Ordner gelten als benutzt
 *  - Alt-Text/Titel am Original geändert -> in den Ordner übernehmen
 *  - Übernahme von Alt-Text/Titel eingeschaltet -> alles einmal abgleichen
 *
 * Danach gilt das Medium als offen und wird beim nächsten Lauf (geplante
 * Aufgabe, Admin oder CLI) neu umgewandelt.
 */
class MediaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConversionService $conversionService,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ScheduledTaskConfigurator $taskConfigurator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'media.written' => 'onMediaWritten',
            'media.deleted' => 'onMediaDeleted',
            'media_thumbnail.written' => 'onThumbnailWritten',
            'media_translation.written' => 'onTranslationWritten',
            UnusedMediaSearchEvent::class => 'onUnusedMediaSearch',
            SystemConfigChangedEvent::class => 'onConfigChanged',
        ];
    }

    public function onMediaWritten(EntityWrittenEvent $event): void
    {
        $paths = [];

        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();

            if (\array_key_exists('path', $payload) && \is_string($result->getPrimaryKey())) {
                $paths[$result->getPrimaryKey()] = (string) $payload['path'];
            }
        }

        if ($paths === []) {
            return;
        }

        $this->safely(fn () => $this->conversionService->removeStaleForMedia(array_keys($paths)));

        // Eintrag im Ordner "Modulwerk WebP-Konverter" umbenannt: Shopware hat die Datei verschoben
        $this->safely(fn () => $this->conversionService->forgetLibraryMedia(
            array_keys($paths),
            'library_renamed',
            static fn (string $libraryId, string $webpPath): bool => ($paths[$libraryId] ?? $webpPath) !== $webpPath
        ));
    }

    public function onMediaDeleted(EntityDeletedEvent $event): void
    {
        $ids = array_values(array_filter($event->getIds(), 'is_string'));

        $this->safely(fn () => $this->conversionService->removeForMedia($ids));
        $this->safely(fn () => $this->conversionService->forgetLibraryMedia($ids, 'library_deleted'));
    }

    public function onTranslationWritten(EntityWrittenEvent $event): void
    {
        $ids = [];

        foreach ($event->getWriteResults() as $result) {
            $key = $result->getPrimaryKey();
            $mediaId = \is_array($key) ? ($key['mediaId'] ?? null) : ($result->getPayload()['mediaId'] ?? null);

            if (\is_string($mediaId)) {
                $ids[$mediaId] = $mediaId;
            }
        }

        if ($ids !== []) {
            $this->safely(fn () => $this->conversionService->syncLibraryTexts(array_values($ids)));
        }
    }

    /**
     * Reagiert auf Änderungen an den Einstellungen: Abstand der geplanten
     * Aufgabe übernehmen bzw. die Übernahme der Texte nachholen.
     *
     * Wird die Übernahme (wieder) eingeschaltet, werden die Texte aller
     * Einträge im Medienordner einmal vom Original übernommen.
     */
    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        if (\in_array($event->getKey(), ['ModulwerkWebp.config.taskInterval', 'ModulwerkWebp.config.taskMode', 'ModulwerkWebp.config.scheduledTask'], true)) {
            $this->safely(fn () => $this->taskConfigurator->apply());

            return;
        }

        if ($event->getKey() !== 'ModulwerkWebp.config.syncTexts' || !$event->getValue()) {
            return;
        }

        $this->safely(fn () => $this->conversionService->syncLibraryTexts());
    }

    public function onUnusedMediaSearch(UnusedMediaSearchEvent $event): void
    {
        $this->safely(function () use ($event): void {
            $used = $this->conversionService->filterLibraryMediaIds(array_values($event->getUnusedIds()));

            if ($used !== []) {
                $event->markAsUsed($used);
            }
        });
    }

    public function onThumbnailWritten(EntityWrittenEvent $event): void
    {
        $ids = array_values(array_filter($event->getIds(), static fn ($id) => \is_string($id) && Uuid::isValid($id)));

        if ($ids === []) {
            return;
        }

        $this->safely(function () use ($ids): void {
            $paths = $this->connection->fetchFirstColumn(
                'SELECT `path` FROM `media_thumbnail` WHERE `id` IN (:ids) AND `path` IS NOT NULL',
                ['ids' => array_map([Uuid::class, 'fromHexToBytes'], $ids)],
                ['ids' => ArrayParameterType::BINARY]
            );

            $this->conversionService->removeByPaths(array_map('strval', $paths));
        });
    }

    /**
     * Ein Fehler hier darf das Speichern eines Mediums nie verhindern.
     */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->logger->warning('[ModulwerkWebp] ' . $e->getMessage());
        }
    }
}
