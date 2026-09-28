<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaType\ImageType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zeigt die WebP-Fassungen der Originalbilder im Admin unter
 * Inhalte → Medien im eigenen Medienordner (Name in den Einstellungen,
 * Standard "Modulwerk WebP-Konverter").
 *
 * Die Einträge zeigen direkt auf die Dateien in modulwerk-webp/ – es wird
 * nichts kopiert. Thumbnails erscheinen nicht im Ordner, sonst stünde dort
 * jedes Bild sechs- bis zehnmal.
 *
 * Alt-Text und Titel werden aus dem Original übernommen, in allen
 * Sprachen, und bei jeder Änderung am Original nachgezogen.
 *
 * Gelöscht werden die Einträge per SQL statt über das Repository: Shopware
 * würde sonst die Datei asynchron löschen – womöglich erst, nachdem sie
 * unter gleichem Pfad neu erzeugt wurde.
 */
class MediaLibraryService
{
    /** Standardname, in den Einstellungen änderbar */
    public const FOLDER_NAME = 'Modulwerk WebP-Konverter';

    private bool $folderChecked = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly WebpConfig $config
    ) {
    }

    /**
     * Medientyp wie ihn Shopware beim Hochladen setzt. PNG-Originale
     * gelten als transparent.
     */
    public static function imageType(bool $transparent): ImageType
    {
        $type = new ImageType();

        if ($transparent) {
            $type->addFlag(ImageType::TRANSPARENT);
        }

        return $type;
    }

    /**
     * Ergänzt den Medientyp bei Einträgen im Ordner, die bis 2.0.15 ohne
     * ihn angelegt wurden. Ohne ihn zeigt die Admin-Sidebar weder
     * "Informationen" noch Alt-Text und Titel.
     */
    public static function repairMediaTypesWith(Connection $connection): int
    {
        $count = 0;

        foreach ([true, false] as $transparent) {
            $count += (int) $connection->executeStatement(
                'UPDATE `media` `m`
                 INNER JOIN `modulwerk_webp_file` `f` ON `f`.`library_media_id` = `m`.`id`
                 LEFT JOIN `media` `o` ON `o`.`id` = `f`.`media_id`
                 SET `m`.`media_type` = :type
                 WHERE `m`.`media_type` IS NULL
                   AND COALESCE(`o`.`mime_type` = \'image/png\', 0) = :png',
                ['type' => serialize(self::imageType($transparent)), 'png' => $transparent ? 1 : 0]
            );
        }

        return $count;
    }

    /**
     * Aktueller Name des Ordners, null wenn er noch nicht existiert.
     */
    public function folderName(): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT `name` FROM `media_folder` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes(self::folderId())]
        );

        return \is_string($name) ? $name : null;
    }

    /**
     * Benennt den Ordner nach der Einstellung um. Der Ordner wird über seine
     * feste ID gefunden, Einträge und Verknüpfungen bleiben also erhalten.
     */
    public function renameFolder(): void
    {
        $this->connection->executeStatement(
            'UPDATE `media_folder` SET `name` = :name, `updated_at` = NOW(3) WHERE `id` = :id AND `name` <> :name',
            ['name' => $this->config->libraryFolderName(), 'id' => Uuid::fromHexToBytes(self::folderId())]
        );
    }

    public static function folderId(): string
    {
        return Uuid::fromStringToHex('modulwerk-webp.media-folder');
    }

    public static function folderConfigurationId(): string
    {
        return Uuid::fromStringToHex('modulwerk-webp.media-folder-configuration');
    }

    /**
     * Legt den Eintrag im Medienordner an und gibt seine ID zurück.
     */
    public function register(string $originalMediaId, string $webpPath, int $size): string
    {
        $this->ensureFolder();

        $original = $this->connection->fetchAssociative(
            'SELECT `file_name`, `meta_data`, `mime_type` FROM `media` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes($originalMediaId)]
        ) ?: [];

        $fileName = (string) ($original['file_name'] ?? '');
        $meta = json_decode((string) ($original['meta_data'] ?? ''), true);
        $width = \is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
        $height = \is_array($meta) ? (int) ($meta['height'] ?? 0) : 0;

        if ($fileName === '') {
            $fileName = pathinfo($webpPath, \PATHINFO_FILENAME);
        }

        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'mediaFolderId' => self::folderId(),
            'mimeType' => 'image/webp',
            'fileExtension' => 'webp',
            'fileName' => $fileName,
            'path' => $webpPath,
            'private' => false,
            'fileSize' => $size,
            'uploadedAt' => new \DateTimeImmutable(),
            'metaData' => ['width' => $width, 'height' => $height, 'type' => \IMAGETYPE_WEBP],
            // Ohne Medientyp bricht die Admin-Sidebar beim Abschnitt
            // "Informationen" ab – dort stehen Alt-Text und Titel
            'mediaTypeRaw' => serialize(self::imageType(\in_array($original['mime_type'] ?? '', ['image/png', 'image/gif'], true))),
        ];

        Context::createDefaultContext()->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($data): void {
            $this->mediaRepository->create([$data], $context);
        });

        return $id;
    }

    /**
     * Kopiert Alt-Text und Titel aller Sprachen vom Original auf den
     * Eintrag im Medienordner – nur Felder, die am Original gefüllt sind. Per SQL, damit keine DAL-Ereignisse
     * ausgelöst werden (kein Kreislauf mit dem Subscriber).
     *
     * @param list<string>|null $originalMediaIds hex; null = alle
     */
    public function syncTexts(?array $originalMediaIds = null): int
    {
        return self::syncTextsWith($this->connection, $originalMediaIds);
    }

    /**
     * Wie syncTexts(), ohne Service – für update() der Plugin-Klasse.
     *
     * @param list<string>|null $originalMediaIds hex; null = alle
     */
    public static function syncTextsWith(Connection $connection, ?array $originalMediaIds = null): int
    {
        $sql = 'INSERT INTO `media_translation` (`media_id`, `language_id`, `alt`, `title`, `created_at`)
                SELECT `f`.`library_media_id`, `t`.`language_id`, `t`.`alt`, `t`.`title`, NOW(3)
                FROM `modulwerk_webp_file` `f`
                INNER JOIN `media_translation` `t` ON `t`.`media_id` = `f`.`media_id`
                INNER JOIN `media` `m` ON `m`.`id` = `f`.`library_media_id`
                WHERE `f`.`library_media_id` IS NOT NULL AND `f`.`is_thumbnail` = 0
                  AND (NULLIF(TRIM(`t`.`alt`), \'\') IS NOT NULL OR NULLIF(TRIM(`t`.`title`), \'\') IS NOT NULL)';
        $params = [];
        $types = [];

        if ($originalMediaIds !== null) {
            $originalMediaIds = array_values(array_filter($originalMediaIds, [Uuid::class, 'isValid']));

            if ($originalMediaIds === []) {
                return 0;
            }

            $sql .= ' AND `f`.`media_id` IN (:ids)';
            $params['ids'] = array_map([Uuid::class, 'fromHexToBytes'], $originalMediaIds);
            $types['ids'] = ArrayParameterType::BINARY;
        }

        /*
         * Nur gefüllte Texte des Originals übernehmen: Ein leeres Feld am
         * Original löscht keinen Text, der am WebP-Eintrag steht.
         * updated_at zuerst, solange alt/title noch die alten Werte haben.
         */
        $sql .= ' ON DUPLICATE KEY UPDATE
                    `media_translation`.`updated_at` = IF(
                        (NULLIF(TRIM(VALUES(`alt`)), \'\') IS NULL OR `media_translation`.`alt` <=> VALUES(`alt`))
                        AND (NULLIF(TRIM(VALUES(`title`)), \'\') IS NULL OR `media_translation`.`title` <=> VALUES(`title`)),
                        `media_translation`.`updated_at`, NOW(3)
                    ),
                    `media_translation`.`alt` = IF(NULLIF(TRIM(VALUES(`alt`)), \'\') IS NULL, `media_translation`.`alt`, VALUES(`alt`)),
                    `media_translation`.`title` = IF(NULLIF(TRIM(VALUES(`title`)), \'\') IS NULL, `media_translation`.`title`, VALUES(`title`))';

        return (int) $connection->executeStatement($sql, $params, $types);
    }

    /**
     * @param list<string> $binaryIds
     */
    public function removeBinaryIds(array $binaryIds): void
    {
        $binaryIds = array_values(array_filter($binaryIds, static fn ($id) => \is_string($id) && \strlen($id) === 16));

        foreach (array_chunk($binaryIds, 500) as $chunk) {
            $this->connection->executeStatement(
                'DELETE FROM `media` WHERE `id` IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::BINARY]
            );
        }
    }

    public function countEntries(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `media` WHERE `media_folder_id` = :folder AND `mime_type` = :mime',
            ['folder' => Uuid::fromHexToBytes(self::folderId()), 'mime' => 'image/webp']
        );
    }

    public function ensureFolder(): void
    {
        if ($this->folderChecked) {
            return;
        }

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM `media_folder` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes(self::folderId())]
        );

        if ($exists === false) {
            $configurationExists = $this->connection->fetchOne(
                'SELECT 1 FROM `media_folder_configuration` WHERE `id` = :id',
                ['id' => Uuid::fromHexToBytes(self::folderConfigurationId())]
            );

            $configuration = $configurationExists !== false
                ? ['id' => self::folderConfigurationId()]
                : [
                    'id' => self::folderConfigurationId(),
                    'createThumbnails' => false,
                    'keepAspectRatio' => true,
                    'private' => false,
                    'noAssociation' => true,
                ];

            Context::createDefaultContext()->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($configuration): void {
                $this->mediaFolderRepository->create([[
                    'id' => self::folderId(),
                    'name' => $this->config->libraryFolderName(),
                    'useParentConfiguration' => false,
                    'configuration' => $configuration,
                ]], $context);
            });
        }

        $this->folderChecked = true;
    }
}
