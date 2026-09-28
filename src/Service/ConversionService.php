<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Erzeugt, zählt und entfernt die WebP-Dateien.
 *
 * Jede Bilddatei (Original und jedes Thumbnail) bekommt eine Zeile in
 * modulwerk_webp_file. Ein Medium gilt als offen, solange für eine seiner
 * Dateien noch keine Zeile existiert. Fehler und übersprungene Dateien
 * haben ebenfalls eine Zeile – so läuft kein Bild in eine Endlosschleife.
 */
class ConversionService
{
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    private const TABLE = 'modulwerk_webp_file';

    /** Ab so vielen fehlgeschlagenen Einträgen wird der Medienordner in diesem Prozess nicht weiter nachgetragen */
    private const LIBRARY_FAILED_MAX = 1000;

    /**
     * Pfade (hex => Binär-Hash), deren Eintrag im Medienordner in diesem
     * Prozess nicht angelegt werden konnte. Sie werden beim Nachtragen
     * übersprungen, sonst liefe syncLibrary() immer wieder in dieselben
     * Fehler – auf der Konsole sogar endlos.
     *
     * @var array<string, string>
     */
    private array $libraryFailed = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemOperator $filesystem,
        private readonly ImageConverter $converter,
        private readonly WebpConfig $config,
        private readonly LoggerInterface $logger,
        private readonly MediaLibraryService $mediaLibrary,
        private readonly WebpCacheService $cache
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Verarbeitung                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Verarbeitet bis zu $limit offene Medien, höchstens aber $maxSeconds
     * lang. Das Zeitlimit schützt vor Timeouts beim Aufruf aus dem Admin.
     *
     * @return array{processedMedia:int, converted:int, skipped:int, errors:int, pending:int, engine:?string}
     */
    public function processPending(int $limit, int $maxSeconds = 20): array
    {
        $result = ['processedMedia' => 0, 'converted' => 0, 'skipped' => 0, 'errors' => 0, 'pending' => 0, 'engine' => null];
        $engine = $this->converter->resolveEngine($this->config->engine());
        $result['engine'] = $engine;

        if ($engine === null) {
            $result['pending'] = $this->countPendingMedia();

            return $result;
        }

        $started = microtime(true);

        foreach ($this->fetchPendingMediaIds($limit) as $mediaId) {
            $stats = $this->convertMedia($mediaId, false, $engine);

            ++$result['processedMedia'];
            $result['converted'] += $stats['converted'];
            $result['skipped'] += $stats['skipped'];
            $result['errors'] += $stats['errors'];

            if ($maxSeconds > 0 && (microtime(true) - $started) >= $maxSeconds) {
                break;
            }
        }

        /*
         * Nachtragen im Medienordner nur, solange noch Zeit ist – sonst im
         * nächsten Schritt. Neu umgewandelte Bilder werden ohnehin sofort
         * eingetragen, hier geht es nur um Nachzügler.
         */
        if ($this->config->mediaLibrary() && ($maxSeconds <= 0 || (microtime(true) - $started) < $maxSeconds)) {
            $this->syncLibrary(50);
        }

        $result['pending'] = $this->countPendingMedia();

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /* Medienordner "Modulwerk WebP-Konverter"                            */
    /* ------------------------------------------------------------------ */

    private function registerInLibrary(string $mediaId, string $path, string $webpPath, int $size): bool
    {
        try {
            $libraryId = $this->mediaLibrary->register($mediaId, $webpPath, $size);

            $this->connection->executeStatement(
                'UPDATE `' . self::TABLE . '` SET `library_media_id` = :library WHERE `path_hash` = :hash',
                ['library' => Uuid::fromHexToBytes($libraryId), 'hash' => WebpPaths::hash($path)]
            );

            // Alt-Text und Titel des Originals übernehmen (abschaltbar).
            // Ein Fehler hier macht den angelegten Eintrag nicht ungültig.
            if ($this->config->syncTexts()) {
                try {
                    $this->mediaLibrary->syncTexts([$mediaId]);
                } catch (\Throwable $e) {
                    $this->logger->warning('[ModulwerkWebp] Alt text/title sync failed for ' . $path . ': ' . $e->getMessage());
                }
            }

            return true;
        } catch (\Throwable $e) {
            // Die Umwandlung selbst ist gelungen, nur der Eintrag im Ordner fehlt
            $hash = WebpPaths::hash($path);
            $this->libraryFailed[bin2hex($hash)] = $hash;
            $this->logger->warning('[ModulwerkWebp] Media folder entry failed for ' . $path . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Medienordner nach der Einstellung "Name des Medienordners" benennen.
     */
    public function renameLibraryFolder(): void
    {
        $this->mediaLibrary->renameFolder();
    }

    /**
     * Trägt bereits umgewandelte Originale nach, die noch keinen Eintrag
     * im Medienordner haben (etwa nach dem Update auf diese Version).
     *
     * @return int Anzahl neu angelegter Einträge – 0, wenn nichts mehr
     *             nachzutragen ist (fehlgeschlagene Einträge zählen nicht)
     */
    public function syncLibrary(int $limit): int
    {
        if (\count($this->libraryFailed) >= self::LIBRARY_FAILED_MAX) {
            return 0;
        }

        $sql = 'SELECT LOWER(HEX(`media_id`)) AS `mediaId`, `path`, `webp_path`, `size_webp`
             FROM `' . self::TABLE . '`
             WHERE `status` = :status AND `is_thumbnail` = 0 AND `library_media_id` IS NULL AND `webp_path` IS NOT NULL';
        $params = ['status' => self::STATUS_CONVERTED];
        $types = [];

        if ($this->libraryFailed !== []) {
            $sql .= ' AND `path_hash` NOT IN (:failed)';
            $params['failed'] = array_values($this->libraryFailed);
            $types['failed'] = ArrayParameterType::BINARY;
        }

        $rows = $this->connection->fetchAllAssociative($sql . ' LIMIT ' . max(1, $limit), $params, $types);
        $registered = 0;

        foreach ($rows as $row) {
            if ($this->registerInLibrary((string) $row['mediaId'], (string) $row['path'], (string) $row['webp_path'], (int) $row['size_webp'])) {
                ++$registered;
            }
        }

        return $registered;
    }

    /**
     * Alt-Text und Titel vom Original auf den Eintrag im Medienordner
     * übertragen, z. B. nachdem sie am Original geändert wurden.
     *
     * @param list<string>|null $mediaIds hex; null = alle
     */
    public function syncLibraryTexts(?array $mediaIds = null): int
    {
        if (!$this->config->syncTexts() || !$this->config->mediaLibrary()) {
            return 0;
        }

        return $this->mediaLibrary->syncTexts($mediaIds);
    }

    /**
     * Ein Eintrag im Medienordner wurde im Admin gelöscht oder umbenannt.
     * Shopware hat die Datei dabei gelöscht bzw. verschoben – die
     * Storefront darf sie also nicht mehr ausliefern. Das Bild wird
     * bewusst nicht automatisch neu erzeugt; "Neu erzeugen" in der
     * Übersicht holt es zurück.
     *
     * @param list<string> $libraryIds hex
     * @param (callable(string, string): bool)|null $filter erhält Eintrags-ID und webp_path, true = betroffen
     */
    public function forgetLibraryMedia(array $libraryIds, string $reason, ?callable $filter = null): int
    {
        $libraryIds = array_values(array_filter($libraryIds, [Uuid::class, 'isValid']));

        if ($libraryIds === []) {
            return 0;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT `path_hash`, `webp_path`, LOWER(HEX(`library_media_id`)) AS `libraryId`
             FROM `' . self::TABLE . '` WHERE `library_media_id` IN (:ids)',
            ['ids' => array_map([Uuid::class, 'fromHexToBytes'], $libraryIds)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $count = 0;

        foreach ($rows as $row) {
            if ($filter !== null && !$filter((string) $row['libraryId'], (string) $row['webp_path'])) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE `' . self::TABLE . '`
                 SET `status` = :status, `webp_path` = NULL, `library_media_id` = NULL, `message` = :message, `updated_at` = :now
                 WHERE `path_hash` = :hash',
                [
                    'status' => self::STATUS_SKIPPED,
                    'message' => ConversionException::encode($reason),
                    'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'hash' => $row['path_hash'],
                ]
            );
            ++$count;
            $this->cache->markDirty();
        }

        return $count;
    }

    /**
     * Welche der übergebenen Medien-IDs sind Einträge im Ordner
     * "Modulwerk WebP-Konverter"? Diese gelten für "Unbenutzte Medien löschen"
     * als benutzt.
     *
     * @param list<string> $ids hex
     *
     * @return list<string> hex
     */
    public function filterLibraryMediaIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, [Uuid::class, 'isValid']));

        if ($ids === []) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(`library_media_id`)) FROM `' . self::TABLE . '` WHERE `library_media_id` IN (:ids)',
            ['ids' => array_map([Uuid::class, 'fromHexToBytes'], $ids)],
            ['ids' => ArrayParameterType::BINARY]
        );

        return $found;
    }

    /**
     * @return array{converted:int, skipped:int, errors:int}
     */
    public function convertMedia(string $mediaId, bool $force = false, ?string $engine = null): array
    {
        $stats = ['converted' => 0, 'skipped' => 0, 'errors' => 0];
        $engine ??= $this->converter->resolveEngine($this->config->engine());

        if ($engine === null || !Uuid::isValid($mediaId)) {
            return $stats;
        }

        $media = $this->connection->fetchAssociative(
            'SELECT `path`, `mime_type` FROM `media`
             WHERE `id` = :id AND `path` IS NOT NULL AND `private` = 0 AND `mime_type` IN (:mimes)',
            ['id' => Uuid::fromHexToBytes($mediaId), 'mimes' => ImageConverter::mimeTypes($this->config->enabledFormats())],
            ['mimes' => ArrayParameterType::STRING]
        );

        if ($media === false) {
            return $stats;
        }

        if ($force) {
            $this->removeForMedia([$mediaId]);
        }

        // Einheitlicher MIME-Typ je Format (image/jpeg, image/png, image/gif, image/bmp, image/tiff)
        $mimeType = ImageConverter::MIME_TYPES[ImageConverter::formatOf((string) $media['mime_type'])][0];
        $files = [];

        if ($this->config->convertOriginals()) {
            $files[] = ['path' => (string) $media['path'], 'thumbnail' => false];
        }

        if ($this->config->convertThumbnails()) {
            $thumbnails = $this->connection->fetchFirstColumn(
                'SELECT `path` FROM `media_thumbnail` WHERE `media_id` = :id AND `path` IS NOT NULL',
                ['id' => Uuid::fromHexToBytes($mediaId)]
            );

            foreach ($thumbnails as $path) {
                $files[] = ['path' => (string) $path, 'thumbnail' => true];
            }
        }

        $known = $this->knownHashes(array_column($files, 'path'));

        foreach ($files as $file) {
            if (isset($known[WebpPaths::hash($file['path'])])) {
                continue;
            }

            $status = $this->convertFile($mediaId, $file['path'], $mimeType, $file['thumbnail'], $engine);
            ++$stats[match ($status) {
                self::STATUS_CONVERTED => 'converted',
                self::STATUS_SKIPPED => 'skipped',
                default => 'errors',
            }];
        }

        return $stats;
    }

    private function convertFile(string $mediaId, string $path, string $mimeType, bool $isThumbnail, string $engine): string
    {
        /*
         * Die Zeile wird VOR der Umwandlung als Fehler angelegt. Bricht PHP
         * mitten drin hart ab (Speicher, Laufzeit), bleibt sie so stehen und
         * das Bild wird beim naechsten Durchlauf nicht erneut versucht.
         */
        $this->writeRow($mediaId, $path, $isThumbnail, self::STATUS_ERROR, null, null, null, ConversionException::encode(ConversionException::ABORTED));

        try {
            $binary = $this->filesystem->read($path);
        } catch (\Throwable $e) {
            $this->writeRow($mediaId, $path, $isThumbnail, self::STATUS_ERROR, null, null, null, ConversionException::encode(ConversionException::READ_FAILED, $e->getMessage()));

            return self::STATUS_ERROR;
        }

        $originalSize = \strlen($binary);

        try {
            if ($engine === 'gd') {
                $this->assertEnoughMemory($binary);
            }

            $quality = $isThumbnail ? $this->config->qualityThumbnail() : $this->config->qualityOriginal();
            $lossless = match ($mimeType) {
                'image/png' => $this->config->losslessPng(),
                'image/gif' => $this->config->losslessGif(),
                'image/bmp' => $this->config->losslessBmp(),
                'image/tiff' => $this->config->losslessTiff(),
                default => $this->config->losslessJpg(),
            };
            $webp = $this->converter->convert($binary, $mimeType, $quality, $lossless, $engine);

            /*
             * Verlustfrei ist bei bereits komprimierten Bildern (vor allem
             * JPG) oft groesser als das Original. Dann mit der eingestellten
             * Qualitaet verlustbehaftet nachlegen, statt das Bild ganz zu
             * ueberspringen.
             */
            if ($lossless && $this->config->skipLarger() && \strlen($webp) >= $originalSize) {
                $webp = $this->converter->convert($binary, $mimeType, $quality, false, $engine);
            }

            unset($binary);

            $webpPath = WebpPaths::forPath($path);

            if ($this->config->skipLarger() && \strlen($webp) >= $originalSize) {
                $this->deleteFile($webpPath);
                $this->writeRow($mediaId, $path, $isThumbnail, self::STATUS_SKIPPED, null, $originalSize, \strlen($webp), ConversionException::encode(ConversionException::NOT_SMALLER));

                return self::STATUS_SKIPPED;
            }

            $this->filesystem->write($webpPath, $webp, ['mimetype' => 'image/webp', 'visibility' => 'public']);
            $this->writeRow($mediaId, $path, $isThumbnail, self::STATUS_CONVERTED, $webpPath, $originalSize, \strlen($webp), null);
            $this->cache->markDirty();

            if (!$isThumbnail && $this->config->mediaLibrary()) {
                $this->registerInLibrary($mediaId, $path, $webpPath, \strlen($webp));
            }

            return self::STATUS_CONVERTED;
        } catch (\Throwable $e) {
            $message = $e instanceof ConversionException
                ? $e->toStoredMessage()
                : ConversionException::encode(ConversionException::CONVERT_FAILED, $e->getMessage());

            /*
             * Farbprofil, das sich nicht nach sRGB umrechnen lässt, oder
             * eine BMP-Variante, die GD nicht lesen kann: kein Fehler, das
             * Bild bleibt bewusst beim Original.
             */
            $status = $e instanceof ConversionException
                && \in_array($e->getMessageCode(), [ConversionException::COLOR_PROFILE, ConversionException::FORMAT_UNSUPPORTED, ConversionException::PIXEL_LIMIT], true)
                ? self::STATUS_SKIPPED
                : self::STATUS_ERROR;

            if ($status === self::STATUS_ERROR) {
                $this->logger->warning('[ModulwerkWebp] ' . $path . ': ' . $e->getMessage());
            }

            $this->writeRow($mediaId, $path, $isThumbnail, $status, null, $originalSize, null, $message);

            return $status;
        }
    }

    /**
     * GD entpackt das Bild komplett in den Speicher (rund 5 Byte je Pixel).
     * Reicht der Speicher nicht, beendet PHP das Skript ohne Ausnahme –
     * deshalb wird vorher gerechnet.
     */
    private function assertEnoughMemory(string $binary): void
    {
        $info = @getimagesizefromstring($binary);

        if (!\is_array($info)) {
            return;
        }

        $needed = (int) ($info[0] * $info[1] * 5 * 1.8) + \strlen($binary) * 2;
        $limit = $this->memoryLimit();

        if ($limit > 0 && memory_get_usage(true) + $needed > $limit) {
            throw new ConversionException(ConversionException::TOO_LARGE, sprintf(
                '%dx%d px, memory_limit %s',
                $info[0],
                $info[1],
                (string) \ini_get('memory_limit')
            ));
        }
    }

    private function memoryLimit(): int
    {
        $value = trim((string) \ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function writeRow(
        string $mediaId,
        string $path,
        bool $isThumbnail,
        string $status,
        ?string $webpPath,
        ?int $sizeOriginal,
        ?int $sizeWebp,
        ?string $message
    ): void {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $this->connection->executeStatement(
            'INSERT INTO `' . self::TABLE . '`
                (`path_hash`, `media_id`, `path`, `webp_path`, `is_thumbnail`, `status`, `size_original`, `size_webp`, `message`, `created_at`)
             VALUES (:hash, :mediaId, :path, :webpPath, :thumb, :status, :sizeOriginal, :sizeWebp, :message, :now)
             ON DUPLICATE KEY UPDATE
                `media_id` = VALUES(`media_id`), `webp_path` = VALUES(`webp_path`), `status` = VALUES(`status`),
                `size_original` = VALUES(`size_original`), `size_webp` = VALUES(`size_webp`),
                `message` = VALUES(`message`), `updated_at` = :now',
            [
                'hash' => WebpPaths::hash($path),
                'mediaId' => Uuid::fromHexToBytes($mediaId),
                'path' => $path,
                'webpPath' => $webpPath,
                'thumb' => $isThumbnail ? 1 : 0,
                'status' => $status,
                'sizeOriginal' => $sizeOriginal,
                'sizeWebp' => $sizeWebp,
                'message' => $message === null ? null : mb_substr($message, 0, 500),
                'now' => $now,
            ]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Offene Medien                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<string> Medien-IDs (hex)
     */
    public function fetchPendingMediaIds(int $limit): array
    {
        [$where, $params, $types] = $this->pendingCondition();

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(m.`id`)) FROM `media` m WHERE ' . $where
            . ' ORDER BY m.`created_at` DESC LIMIT ' . max(1, $limit),
            $params,
            $types
        );

        return $ids;
    }

    public function countPendingMedia(): int
    {
        [$where, $params, $types] = $this->pendingCondition();

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `media` m WHERE ' . $where, $params, $types);
    }

    public function countEligibleMedia(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `media` m WHERE ' . $this->eligibleCondition(),
            ['mimes' => ImageConverter::mimeTypes($this->config->enabledFormats())],
            ['mimes' => ArrayParameterType::STRING]
        );
    }

    private function eligibleCondition(): string
    {
        return 'm.`path` IS NOT NULL AND m.`private` = 0 AND m.`mime_type` IN (:mimes)';
    }

    /**
     * @return array{0:string, 1:array<string,mixed>, 2:array<string,mixed>}
     */
    private function pendingCondition(): array
    {
        $parts = [];

        if ($this->config->convertOriginals()) {
            $parts[] = 'NOT EXISTS (SELECT 1 FROM `' . self::TABLE . '` f WHERE f.`path_hash` = UNHEX(MD5(m.`path`)))';
        }

        if ($this->config->convertThumbnails()) {
            $parts[] = 'EXISTS (SELECT 1 FROM `media_thumbnail` t WHERE t.`media_id` = m.`id` AND t.`path` IS NOT NULL'
                . ' AND NOT EXISTS (SELECT 1 FROM `' . self::TABLE . '` f2 WHERE f2.`path_hash` = UNHEX(MD5(t.`path`))))';
        }

        $pending = $parts === [] ? '0 = 1' : '(' . implode(' OR ', $parts) . ')';

        return [
            $this->eligibleCondition() . ' AND ' . $pending,
            ['mimes' => ImageConverter::mimeTypes($this->config->enabledFormats())],
            ['mimes' => ArrayParameterType::STRING],
        ];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, true> Binär-Hashes, für die schon eine Zeile existiert
     */
    private function knownHashes(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $hashes = $this->connection->fetchFirstColumn(
            'SELECT `path_hash` FROM `' . self::TABLE . '` WHERE `path_hash` IN (:hashes)',
            ['hashes' => array_map([WebpPaths::class, 'hash'], $paths)],
            ['hashes' => ArrayParameterType::BINARY]
        );

        return array_fill_keys(array_map('strval', $hashes), true);
    }

    /* ------------------------------------------------------------------ */
    /* Aufräumen                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param list<string> $mediaIds hex
     */
    public function removeForMedia(array $mediaIds): int
    {
        $mediaIds = array_values(array_filter($mediaIds, [Uuid::class, 'isValid']));

        if ($mediaIds === []) {
            return 0;
        }

        $bytes = array_map([Uuid::class, 'fromHexToBytes'], $mediaIds);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT `path_hash`, `webp_path`, `library_media_id` FROM `' . self::TABLE . '` WHERE `media_id` IN (:ids)',
            ['ids' => $bytes],
            ['ids' => ArrayParameterType::BINARY]
        );

        return $this->removeRows($rows);
    }

    /**
     * Entfernt Einträge, deren Original nicht mehr existiert (Medium
     * gelöscht, Datei ersetzt, Thumbnail-Größe entfernt).
     */
    public function cleanup(): int
    {
        return $this->removeRows($this->connection->fetchAllAssociative($this->staleSql()));
    }

    /**
     * Wie cleanup(), aber nur für einzelne Medien – etwa nachdem die
     * Datei eines Mediums ersetzt wurde.
     *
     * @param list<string> $mediaIds hex
     */
    public function removeStaleForMedia(array $mediaIds): int
    {
        $mediaIds = array_values(array_filter($mediaIds, [Uuid::class, 'isValid']));

        if ($mediaIds === []) {
            return 0;
        }

        return $this->removeRows($this->connection->fetchAllAssociative(
            $this->staleSql() . ' AND f.`media_id` IN (:ids)',
            ['ids' => array_map([Uuid::class, 'fromHexToBytes'], $mediaIds)],
            ['ids' => ArrayParameterType::BINARY]
        ));
    }

    /**
     * Entfernt die Einträge zu genau diesen Dateipfaden. Wird benutzt,
     * wenn Thumbnails unter gleichem Pfad neu erzeugt wurden – die alte
     * WebP-Datei passt dann nicht mehr zum Inhalt.
     *
     * @param list<string> $paths
     */
    public function removeByPaths(array $paths): int
    {
        if ($paths === []) {
            return 0;
        }

        return $this->removeRows($this->connection->fetchAllAssociative(
            'SELECT `path_hash`, `webp_path`, `library_media_id` FROM `' . self::TABLE . '` WHERE `path_hash` IN (:hashes)',
            ['hashes' => array_map([WebpPaths::class, 'hash'], array_values(array_unique($paths)))],
            ['hashes' => ArrayParameterType::BINARY]
        ));
    }

    private function staleSql(): string
    {
        return 'SELECT f.`path_hash`, f.`webp_path`, f.`library_media_id` FROM `' . self::TABLE . '` f
             WHERE NOT EXISTS (SELECT 1 FROM `media` m WHERE m.`id` = f.`media_id` AND m.`path` = f.`path`)
               AND NOT EXISTS (SELECT 1 FROM `media_thumbnail` t WHERE t.`media_id` = f.`media_id` AND t.`path` = f.`path`)';
    }

    public function resetErrors(bool $includeSkipped = false): int
    {
        $statuses = $includeSkipped ? [self::STATUS_ERROR, self::STATUS_SKIPPED] : [self::STATUS_ERROR];

        return (int) $this->connection->executeStatement(
            'DELETE FROM `' . self::TABLE . '` WHERE `status` IN (:statuses)',
            ['statuses' => $statuses],
            ['statuses' => ArrayParameterType::STRING]
        );
    }

    public function clearAll(): void
    {
        $this->mediaLibrary->removeBinaryIds(array_map('strval', $this->connection->fetchFirstColumn(
            'SELECT `library_media_id` FROM `' . self::TABLE . '` WHERE `library_media_id` IS NOT NULL'
        )));

        try {
            $this->filesystem->deleteDirectory(WebpPaths::DIRECTORY);
        } catch (\Throwable $e) {
            $this->logger->warning('[ModulwerkWebp] Could not delete directory: ' . $e->getMessage());
        }

        $this->connection->executeStatement('DELETE FROM `' . self::TABLE . '`');
        $this->cache->markDirty();
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function removeRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $libraryIds = [];

        foreach ($rows as $row) {
            if (!empty($row['webp_path'])) {
                $this->deleteFile((string) $row['webp_path']);
                $this->cache->markDirty();
            }

            if (!empty($row['library_media_id'])) {
                $libraryIds[] = (string) $row['library_media_id'];
            }
        }

        $this->mediaLibrary->removeBinaryIds($libraryIds);

        foreach (array_chunk(array_column($rows, 'path_hash'), 500) as $chunk) {
            $this->connection->executeStatement(
                'DELETE FROM `' . self::TABLE . '` WHERE `path_hash` IN (:hashes)',
                ['hashes' => $chunk],
                ['hashes' => ArrayParameterType::BINARY]
            );
        }

        return \count($rows);
    }

    private function deleteFile(string $path): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (\Throwable) {
            // existiert nicht oder ist schon weg
        }
    }

    /* ------------------------------------------------------------------ */
    /* Auswertung                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $byStatus = $this->connection->fetchAllAssociative(
            'SELECT `status`, COUNT(*) AS `files`, COALESCE(SUM(`size_original`), 0) AS `original`,
                    COALESCE(SUM(`size_webp`), 0) AS `webp`
             FROM `' . self::TABLE . '` GROUP BY `status`'
        );

        $files = [self::STATUS_CONVERTED => 0, self::STATUS_SKIPPED => 0, self::STATUS_ERROR => 0];
        $bytesOriginal = 0;
        $bytesWebp = 0;

        foreach ($byStatus as $row) {
            $files[(string) $row['status']] = (int) $row['files'];

            if ($row['status'] === self::STATUS_CONVERTED) {
                $bytesOriginal = (int) $row['original'];
                $bytesWebp = (int) $row['webp'];
            }
        }

        $engine = $this->converter->resolveEngine($this->config->engine());

        return [
            'mediaTotal' => $this->countEligibleMedia(),
            'mediaPending' => $this->countPendingMedia(),
            'filesConverted' => $files[self::STATUS_CONVERTED],
            'filesSkipped' => $files[self::STATUS_SKIPPED],
            'filesError' => $files[self::STATUS_ERROR],
            'bytesOriginal' => $bytesOriginal,
            'bytesWebp' => $bytesWebp,
            'engine' => $engine,
            'gdAvailable' => $this->converter->gdAvailable(),
            'imagickAvailable' => $this->converter->imagickAvailable(),
            'memoryLimit' => (string) \ini_get('memory_limit'),
            'directory' => WebpPaths::DIRECTORY,
            'mediaLibrary' => $this->config->mediaLibrary(),
            'libraryEntries' => $this->mediaLibrary->countEntries(),
            'libraryFolderName' => $this->mediaLibrary->folderName() ?? $this->config->libraryFolderName(),
            'libraryFolderId' => MediaLibraryService::folderId(),
            'autoClearCache' => $this->cache->isEnabled(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEntries(int $limit, ?string $status = null): array
    {
        $sql = 'SELECT LOWER(HEX(`media_id`)) AS `mediaId`, `path`, `webp_path` AS `webpPath`,
                       `is_thumbnail` AS `isThumbnail`, `status`, `size_original` AS `sizeOriginal`,
                       `size_webp` AS `sizeWebp`, `message`, COALESCE(`updated_at`, `created_at`) AS `date`
                FROM `' . self::TABLE . '`';
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE `status` = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY COALESCE(`updated_at`, `created_at`) DESC LIMIT ' . max(1, min($limit, 500));

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(static function (array $row): array {
            $row['isThumbnail'] = (bool) $row['isThumbnail'];
            $row['sizeOriginal'] = $row['sizeOriginal'] === null ? null : (int) $row['sizeOriginal'];
            $row['sizeWebp'] = $row['sizeWebp'] === null ? null : (int) $row['sizeWebp'];

            return $row;
        }, $rows);
    }

    /**
     * Pfade, für die eine WebP-Datei ausgeliefert werden darf.
     *
     * @param list<string> $paths
     *
     * @return array<string, true> Pfad => true
     */
    public function filterConvertedPaths(array $paths): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($paths)), 500) as $chunk) {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT `path` FROM `' . self::TABLE . '` WHERE `path_hash` IN (:hashes) AND `status` = :status',
                ['hashes' => array_map([WebpPaths::class, 'hash'], $chunk), 'status' => self::STATUS_CONVERTED],
                ['hashes' => ArrayParameterType::BINARY]
            );

            foreach ($rows as $path) {
                $found[(string) $path] = true;
            }
        }

        return $found;
    }
}
