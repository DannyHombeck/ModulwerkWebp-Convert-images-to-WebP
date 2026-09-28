<?php declare(strict_types=1);

namespace ModulwerkWebp\Controller;

use ModulwerkWebp\Service\ConversionService;
use ModulwerkWebp\Service\WebpCacheService;
use ModulwerkWebp\Service\WebpConfig;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpunkte für die Übersicht im Admin. Die Umwandlung läuft in kleinen
 * Portionen, der Admin ruft /process so lange auf, bis nichts mehr offen
 * ist. So kommt es auch bei knappem max_execution_time nicht zum Abbruch.
 *
 * Rechte: Lesen braucht media:read, Umwandeln media:update, Löschen der
 * WebP-Dateien media:delete. Administratoren haben alle Rechte.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class WebpApiController extends AbstractController
{
    public function __construct(
        private readonly ConversionService $conversionService,
        private readonly WebpConfig $config,
        private readonly WebpCacheService $cache
    ) {
    }

    #[Route(path: '/api/_action/modulwerk-webp/status', name: 'api.action.modulwerk_webp.status', defaults: ['_acl' => ['media:read']], methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->conversionService->getStatus() + ['batchSize' => $this->config->batchSize()]);
    }

    #[Route(path: '/api/_action/modulwerk-webp/entries', name: 'api.action.modulwerk_webp.entries', defaults: ['_acl' => ['media:read']], methods: ['GET'])]
    public function entries(Request $request): JsonResponse
    {
        $status = $request->query->get('status');

        return new JsonResponse([
            'entries' => $this->conversionService->getEntries(
                (int) $request->query->get('limit', '50'),
                \is_string($status) ? $status : null
            ),
        ]);
    }

    #[Route(path: '/api/_action/modulwerk-webp/process', name: 'api.action.modulwerk_webp.process', defaults: ['_acl' => ['media:update']], methods: ['POST'])]
    public function process(Request $request): JsonResponse
    {
        $limit = (int) $request->request->get('limit', (string) $this->config->batchSize());
        $limit = $limit > 0 ? min($limit, 500) : $this->config->batchSize();

        // Geleert wird erst am Ende des ganzen Laufs (finish), nicht je Schritt
        $this->cache->defer();

        return new JsonResponse($this->conversionService->processPending($limit, 15));
    }

    /**
     * Meldet der Admin nach dem letzten Schritt. Leert den HTTP-Cache,
     * wenn während des Laufs etwas umgewandelt wurde.
     */
    #[Route(path: '/api/_action/modulwerk-webp/finish', name: 'api.action.modulwerk_webp.finish', defaults: ['_acl' => ['media:update']], methods: ['POST'])]
    public function finish(Request $request): JsonResponse
    {
        $changed = (bool) $request->request->get('changed', false);

        return new JsonResponse(['cacheCleared' => $changed && $this->cache->flush(true)]);
    }

    #[Route(path: '/api/_action/modulwerk-webp/media/{mediaId}', name: 'api.action.modulwerk_webp.media', defaults: ['_acl' => ['media:update']], methods: ['POST'])]
    public function media(string $mediaId): JsonResponse
    {
        $result = $this->conversionService->convertMedia(strtolower($mediaId), true);

        return new JsonResponse($result + ['cacheCleared' => $this->cache->flush()]);
    }

    #[Route(path: '/api/_action/modulwerk-webp/reset-errors', name: 'api.action.modulwerk_webp.reset_errors', defaults: ['_acl' => ['media:update']], methods: ['POST'])]
    public function resetErrors(Request $request): JsonResponse
    {
        return new JsonResponse([
            'reset' => $this->conversionService->resetErrors((bool) $request->request->get('includeSkipped', false)),
        ]);
    }

    #[Route(path: '/api/_action/modulwerk-webp/cleanup', name: 'api.action.modulwerk_webp.cleanup', defaults: ['_acl' => ['media:delete']], methods: ['POST'])]
    public function cleanup(): JsonResponse
    {
        $removed = $this->conversionService->cleanup();

        return new JsonResponse(['removed' => $removed, 'cacheCleared' => $this->cache->flush()]);
    }

    #[Route(path: '/api/_action/modulwerk-webp/clear', name: 'api.action.modulwerk_webp.clear', defaults: ['_acl' => ['media:delete']], methods: ['POST'])]
    public function clear(Request $request): JsonResponse
    {
        /*
         * "Alle Bilder neu umwandeln" löscht zuerst und wandelt dann um –
         * der Cache wird in dem Fall erst am Ende des Laufs geleert.
         */
        if ($request->request->get('deferCache', false)) {
            $this->cache->defer();
        }

        $this->conversionService->clearAll();

        return new JsonResponse(['cleared' => true, 'cacheCleared' => $this->cache->flush()]);
    }
}
