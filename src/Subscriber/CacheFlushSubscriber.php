<?php declare(strict_types=1);

namespace ModulwerkWebp\Subscriber;

use ModulwerkWebp\Service\WebpCacheService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Leert den HTTP-Cache, wenn sich während einer Anfrage, eines
 * Konsolenbefehls oder einer Worker-Nachricht an den WebP-Dateien etwas
 * geändert hat (z. B. Medium gelöscht, Thumbnails neu erzeugt).
 */
class CacheFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly WebpCacheService $cache)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
            WorkerMessageHandledEvent::class => 'flush',
        ];
    }

    public function flush(): void
    {
        $this->cache->flush();
    }
}
