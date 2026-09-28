<?php declare(strict_types=1);

namespace ModulwerkWebp;

use Doctrine\DBAL\Connection;
use ModulwerkWebp\Service\MediaLibraryService;
use ModulwerkWebp\Service\WebpPaths;
use League\Flysystem\FilesystemOperator;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;

class ModulwerkWebp extends Plugin
{
    /**
     * Tabellen, die dieses Plugin anlegt. Beim Deinstallieren werden sie
     * in umgekehrter Reihenfolge entfernt.
     */
    private const PLUGIN_TABLES = [
        'modulwerk_webp_file',
    ];

    /**
     * Ordner aus älteren Versionen umbenennen ("WebP-Konverter" ->
     * "Modulwerk WebP-Konverter"). Hat der Anwender den Ordner selbst
     * umbenannt, bleibt sein Name stehen.
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $connection = $this->container?->get(Connection::class);

        if (!$connection instanceof Connection) {
            return;
        }

        try {
            $connection->executeStatement(
                'UPDATE `media_folder` SET `name` = :name WHERE `id` = :id AND `name` = :old',
                [
                    'name' => MediaLibraryService::FOLDER_NAME,
                    'old' => 'WebP-Konverter',
                    'id' => Uuid::fromHexToBytes(MediaLibraryService::folderId()),
                ]
            );
        } catch (\Throwable) {
            // Ordner existiert noch nicht
        }

        $this->migrateTaskInterval($connection);

        // Fehlenden Medientyp bei vorhandenen Ordner-Einträgen ergänzen
        try {
            MediaLibraryService::repairMediaTypesWith($connection);
        } catch (\Throwable) {
            // Tabelle existiert noch nicht
        }

        // Alt-Text und Titel der Originale in bestehende Ordner-Einträge übernehmen
        try {
            $hasColumn = $connection->fetchFirstColumn('SHOW COLUMNS FROM `modulwerk_webp_file` LIKE \'library_media_id\'');

            // Einstellung direkt lesen, der Plugin-Container ist hier noch nicht aktiv
            $raw = $connection->fetchOne(
                'SELECT `configuration_value` FROM `system_config`
                 WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL',
                ['key' => 'ModulwerkWebp.config.syncTexts']
            );
            $value = \is_string($raw) ? json_decode($raw, true) : null;
            $enabled = !\is_array($value) || !\array_key_exists('_value', $value) || (bool) $value['_value'];

            if ($hasColumn !== [] && $enabled) {
                MediaLibraryService::syncTextsWith($connection);
            }
        } catch (\Throwable) {
            // wird beim nächsten Anlegen eines Eintrags nachgeholt
        }
    }

    /**
     * Bis 2.0.13 wurde der Abstand der geplanten Aufgabe in Minuten
     * eingestellt ("taskInterval"), jetzt in Stunden ("taskIntervalHours").
     * Der alte Wert wird einmalig auf volle Stunden aufgerundet übernommen
     * (mindestens 1) und danach entfernt.
     */
    private function migrateTaskInterval(Connection $connection): void
    {
        try {
            $rows = $connection->fetchAllAssociative(
                'SELECT `sales_channel_id`, `configuration_value` FROM `system_config`
                 WHERE `configuration_key` = :key',
                ['key' => 'ModulwerkWebp.config.taskInterval']
            );

            foreach ($rows as $row) {
                $value = json_decode((string) $row['configuration_value'], true);
                $minutes = \is_array($value) ? (int) ($value['_value'] ?? 0) : 0;
                $hours = $minutes > 0 ? max(1, min(168, (int) ceil($minutes / 60))) : 24;
                $salesChannelId = $row['sales_channel_id'];

                $existing = $connection->fetchOne(
                    'SELECT `id` FROM `system_config` WHERE `configuration_key` = :key AND '
                    . ($salesChannelId === null ? '`sales_channel_id` IS NULL' : '`sales_channel_id` = :channel'),
                    array_filter(['key' => 'ModulwerkWebp.config.taskIntervalHours', 'channel' => $salesChannelId], static fn ($v) => $v !== null)
                );

                $json = json_encode(['_value' => $hours]);

                if ($existing !== false) {
                    $connection->executeStatement(
                        'UPDATE `system_config` SET `configuration_value` = :value, `updated_at` = NOW(3) WHERE `id` = :id',
                        ['value' => $json, 'id' => $existing]
                    );
                } else {
                    $connection->executeStatement(
                        'INSERT INTO `system_config` (`id`, `configuration_key`, `configuration_value`, `sales_channel_id`, `created_at`)
                         VALUES (:id, :key, :value, :channel, NOW(3))',
                        [
                            'id' => Uuid::randomBytes(),
                            'key' => 'ModulwerkWebp.config.taskIntervalHours',
                            'value' => $json,
                            'channel' => $salesChannelId,
                        ]
                    );
                }
            }

            $connection->executeStatement(
                'DELETE FROM `system_config` WHERE `configuration_key` = :key',
                ['key' => 'ModulwerkWebp.config.taskInterval']
            );
        } catch (\Throwable) {
            // Ohne Übernahme gilt der Standard von 24 Stunden
        }
    }

    /**
     * Entfernt beim Deinstallieren alle Spuren des Plugins,
     * sofern der Anwender die Daten nicht behalten möchte.
     *
     * Die Originalbilder werden nie angefasst. Gelöscht wird nur das
     * eigene Verzeichnis mit den WebP-Dateien.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);

        if (!$connection instanceof Connection) {
            return;
        }

        $this->removeFiles();
        $this->removeMediaFolder($connection);
        $this->removeTables($connection);
        $this->removeConfiguration($connection);
        $this->removeScheduledTask($connection);
    }

    private function removeFiles(): void
    {
        try {
            $filesystem = $this->container?->get('shopware.filesystem.public');

            if ($filesystem instanceof FilesystemOperator) {
                $filesystem->deleteDirectory(WebpPaths::DIRECTORY);
            }

            return;
        } catch (\Throwable) {
            // weiter mit dem lokalen Pfad
        }

        // Rückfall für das lokale Dateisystem
        try {
            $projectDir = $this->container?->getParameter('kernel.project_dir');

            if (\is_string($projectDir)) {
                $this->deleteLocalDirectory($projectDir . '/public/' . WebpPaths::DIRECTORY);
            }
        } catch (\Throwable) {
            // Dateien notfalls von Hand entfernen: public/modulwerk-webp
        }
    }

    private function deleteLocalDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * Einträge und Ordner "Modulwerk WebP-Konverter" unter Inhalte → Medien.
     * Per SQL, damit Shopware keine Dateien löscht – das Verzeichnis ist
     * zu diesem Zeitpunkt ohnehin schon weg.
     */
    private function removeMediaFolder(Connection $connection): void
    {
        try {
            $hasColumn = $connection->fetchFirstColumn('SHOW COLUMNS FROM `modulwerk_webp_file` LIKE \'library_media_id\'');

            if ($hasColumn !== []) {
                $connection->executeStatement(
                    'DELETE `m` FROM `media` `m`
                     INNER JOIN `modulwerk_webp_file` `f` ON `f`.`library_media_id` = `m`.`id`'
                );
            }
        } catch (\Throwable) {
            // Tabelle fehlt bereits
        }

        try {
            $connection->executeStatement(
                'UPDATE `media` SET `media_folder_id` = NULL WHERE `media_folder_id` = :folder',
                ['folder' => Uuid::fromHexToBytes(MediaLibraryService::folderId())]
            );
            $connection->executeStatement(
                'DELETE FROM `media_folder` WHERE `id` = :folder',
                ['folder' => Uuid::fromHexToBytes(MediaLibraryService::folderId())]
            );
            $connection->executeStatement(
                'DELETE FROM `media_folder_configuration` WHERE `id` = :configuration',
                ['configuration' => Uuid::fromHexToBytes(MediaLibraryService::folderConfigurationId())]
            );
        } catch (\Throwable) {
            // Ordner notfalls im Admin von Hand löschen
        }
    }

    private function removeTables(Connection $connection): void
    {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse(self::PLUGIN_TABLES) as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function removeConfiguration(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` LIKE :key',
            ['key' => 'ModulwerkWebp.config.%']
        );
    }

    private function removeScheduledTask(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM `scheduled_task` WHERE `name` = :name',
            ['name' => 'modulwerk_webp.convert']
        );
    }
}
