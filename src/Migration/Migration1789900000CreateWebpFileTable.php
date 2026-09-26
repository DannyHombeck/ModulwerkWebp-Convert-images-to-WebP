<?php declare(strict_types=1);

namespace ModulwerkWebp\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Eine Zeile je Bilddatei (Original oder Thumbnail).
 *
 * path_hash = MD5 des Medienpfads, dient als Schlüssel für die schnelle
 * Suche beim Umschreiben der Storefront-Ausgabe.
 */
class Migration1789900000CreateWebpFileTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789900000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS `modulwerk_webp_file` (
                `path_hash`     BINARY(16)    NOT NULL,
                `media_id`      BINARY(16)    NOT NULL,
                `path`          VARCHAR(1024) NOT NULL,
                `webp_path`     VARCHAR(1100) NULL,
                `is_thumbnail`  TINYINT(1)    NOT NULL DEFAULT 0,
                `status`        VARCHAR(16)   NOT NULL,
                `size_original` INT UNSIGNED  NULL,
                `size_webp`     INT UNSIGNED  NULL,
                `message`       VARCHAR(500)  NULL,
                `created_at`    DATETIME(3)   NOT NULL,
                `updated_at`    DATETIME(3)   NULL,
                PRIMARY KEY (`path_hash`),
                KEY `idx.modulwerk_webp_file.media_id` (`media_id`),
                KEY `idx.modulwerk_webp_file.status` (`status`),
                KEY `idx.modulwerk_webp_file.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Bewusst leer: das Aufräumen erledigt die uninstall()-Routine der Plugin-Klasse.
    }
}
