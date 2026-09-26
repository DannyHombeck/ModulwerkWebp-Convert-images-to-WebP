<?php declare(strict_types=1);

namespace ModulwerkWebp\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Verknüpft eine WebP-Datei mit ihrem Eintrag im Medienordner
 * "Modulwerk WebP-Konverter" (Inhalte → Medien).
 */
class Migration1790000000AddLibraryMediaId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790000000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `modulwerk_webp_file` LIKE \'library_media_id\'');

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `modulwerk_webp_file`
                ADD COLUMN `library_media_id` BINARY(16) NULL AFTER `webp_path`,
                ADD KEY `idx.modulwerk_webp_file.library_media_id` (`library_media_id`)'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
