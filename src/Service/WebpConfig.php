<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class WebpConfig
{
    private const PREFIX = 'ModulwerkWebp.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function isDeliveryActive(?string $salesChannelId = null): bool
    {
        return $this->bool('active', $salesChannelId, true);
    }

    public function convertOriginals(): bool
    {
        return $this->bool('convertOriginals', null, true);
    }

    public function convertThumbnails(): bool
    {
        return $this->bool('convertThumbnails', null, true);
    }

    public function qualityOriginal(): int
    {
        return $this->quality('qualityOriginal', 82);
    }

    public function qualityThumbnail(): int
    {
        return $this->quality('qualityThumbnail', 78);
    }

    public function losslessPng(): bool
    {
        return $this->bool('losslessPng', null, false);
    }

    public function losslessJpg(): bool
    {
        return $this->bool('losslessJpg', null, false);
    }

    public function autoClearCache(): bool
    {
        return $this->bool('autoClearCache', null, true);
    }

    public function syncTexts(): bool
    {
        return $this->bool('syncTexts', null, true);
    }

    public function mediaLibrary(): bool
    {
        return $this->bool('mediaLibrary', null, true);
    }

    public function skipLarger(): bool
    {
        return $this->bool('skipLarger', null, true);
    }

    public function engine(): string
    {
        $engine = (string) $this->systemConfigService->get(self::PREFIX . 'engine');

        return \in_array($engine, ['gd', 'imagick'], true) ? $engine : 'auto';
    }

    /**
     * Abstand der geplanten Aufgabe in Minuten.
     */
    public function taskIntervalMinutes(): int
    {
        $value = (int) $this->systemConfigService->get(self::PREFIX . 'taskInterval');

        return $value > 0 ? min($value, 1440) : 1440;
    }

    /**
     * 'interval' = alle X Minuten, 'daily' = einmal täglich zur Uhrzeit.
     */
    public function taskMode(): string
    {
        return (string) $this->systemConfigService->get(self::PREFIX . 'taskMode') === 'daily' ? 'daily' : 'interval';
    }

    /**
     * Uhrzeit für den täglichen Lauf als [Stunde, Minute] in Serverzeit.
     *
     * @return array{0:int, 1:int}
     */
    public function taskTime(): array
    {
        $value = trim((string) $this->systemConfigService->get(self::PREFIX . 'taskTime'));

        if (preg_match('/^(\\d{1,2})[:.](\\d{2})$/', $value, $match) !== 1) {
            return [3, 0];
        }

        return [min(23, (int) $match[1]), min(59, (int) $match[2])];
    }

    /**
     * Datum des letzten täglichen Laufs (Y-m-d) – damit er auch dann
     * genau einmal am Tag läuft, wenn die Aufgabe häufiger geprüft wird.
     */
    public function lastDailyRun(): string
    {
        return (string) $this->systemConfigService->get(self::PREFIX . 'lastDailyRun');
    }

    public function setLastDailyRun(string $date): void
    {
        $this->systemConfigService->set(self::PREFIX . 'lastDailyRun', $date);
    }

    public function batchSize(): int
    {
        $value = (int) $this->systemConfigService->get(self::PREFIX . 'batchSize');

        return $value > 0 ? min($value, 500) : 20;
    }

    public function scheduledTaskActive(): bool
    {
        return $this->bool('scheduledTask', null, true);
    }

    public function lazyLoadActive(?string $salesChannelId = null): bool
    {
        return $this->bool('lazyLoad', $salesChannelId, false);
    }

    public function lazyLoadSkip(?string $salesChannelId = null): int
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'lazyLoadSkip', $salesChannelId);

        return $value === null || $value === '' ? 2 : max(0, (int) $value);
    }

    /**
     * @return list<string> Teilstrings von Pfaden, die nie umgeschrieben werden
     */
    public function excludedPatterns(?string $salesChannelId = null): array
    {
        $raw = (string) $this->systemConfigService->get(self::PREFIX . 'excludePaths', $salesChannelId);
        $lines = preg_split('/[\r\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line) => $line !== ''));
    }

    private function bool(string $key, ?string $salesChannelId, bool $default): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . $key, $salesChannelId);

        return $value === null ? $default : (bool) $value;
    }

    private function quality(string $key, int $default): int
    {
        $value = $this->systemConfigService->get(self::PREFIX . $key);

        if ($value === null || $value === '') {
            return $default;
        }

        return max(1, min(100, (int) $value));
    }
}
