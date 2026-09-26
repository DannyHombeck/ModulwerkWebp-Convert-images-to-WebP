<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheClearer;

/**
 * Leert nach einer Verarbeitung automatisch den HTTP-Cache der Storefront.
 *
 * Geleert wird nur der Seiten-Cache (bzw. Varnish/Reverse-Proxy), nicht der
 * komplette Shopware-Cache. Das reicht, weil die WebP-URLs erst beim
 * Ausliefern der fertigen Seite eingesetzt werden.
 *
 * Änderungen setzen nur eine Markierung. Geleert wird einmal am Ende –
 * nach der Anfrage, dem Konsolenbefehl oder der Nachricht im Worker.
 */
class WebpCacheService
{
    private bool $dirty = false;

    private bool $deferred = false;

    public function __construct(
        private readonly CacheClearer $cacheClearer,
        private readonly WebpConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function markDirty(): void
    {
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Für die schrittweise Umwandlung aus dem Admin: nicht nach jedem
     * Schritt leeren, sondern erst, wenn der Admin das Ende meldet.
     */
    public function defer(): void
    {
        $this->deferred = true;
    }

    public function isEnabled(): bool
    {
        return $this->config->autoClearCache();
    }

    /**
     * @return bool true, wenn geleert wurde
     */
    public function flush(bool $force = false): bool
    {
        if ((!$this->dirty && !$force) || ($this->deferred && !$force) || !$this->isEnabled()) {
            return false;
        }

        $this->dirty = false;

        try {
            $this->cacheClearer->clearHttpCache();

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[ModulwerkWebp] HTTP cache could not be cleared: ' . $e->getMessage());

            return false;
        }
    }
}
