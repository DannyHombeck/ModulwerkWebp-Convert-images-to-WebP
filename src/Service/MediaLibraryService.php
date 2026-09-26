<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zeigt die WebP-Fassungen der Originalbilder im Admin unter
 * Inhalte → Medien im Ordner "Modulwerk WebP-Konverter".
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
    public const FOLDER_NAME = 'Modulwerk WebP-Konverter';

    private bool $folderChecked = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository
    ) {
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
            'SELECT `file_name`, `meta_data` FROM `media` WHERE `id` = :id',
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
        ];

        Context::createDefaultContext()->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($data): void {
            $this->mediaRepository->create([$data], $context);
        });

        return $id;
    }

    /**
     * Kopiert Alt-Text und Titel aller Sprachen vom Original auf den
     * Eintrag im Medienordner. Per SQL, damit keine DAL-Ereignisse
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
                WHERE `f`.`library_media_id` IS NOT NULL AND `f`.`is_thumbnail` = 0';
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

        // updated_at zuerst, solange alt/title noch die alten Werte haben
        $sql .= ' ON DUPLICATE KEY UPDATE
                    `updated_at` = IF(`alt` <=> VALUES(`alt`) AND `title` <=> VALUES(`title`), `updated_at`, NOW(3)),
                    `alt` = VALUES(`alt`), `title` = VALUES(`title`)';

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
                    'name' => self::FOLDER_NAME,
                    'useParentConfiguration' => false,
                    'configuration' => $configuration,
                ]], $context);
            });
        }

        $this->folderChecked = true;
    }
}
