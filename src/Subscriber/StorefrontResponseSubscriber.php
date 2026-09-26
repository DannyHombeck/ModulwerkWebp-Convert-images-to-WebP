<?php declare(strict_types=1);

namespace ModulwerkWebp\Subscriber;

use ModulwerkWebp\Service\HtmlRewriter;
use ModulwerkWebp\Service\WebpConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Schreibt die Bild-URLs in Storefront-Antworten auf WebP um.
 *
 * Läuft vor dem HTTP-Cache, der die fertige Seite speichert. Nach dem
 * Umwandeln neuer Bilder muss der Cache deshalb geleert werden.
 */
class StorefrontResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly HtmlRewriter $rewriter,
        private readonly WebpConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -20],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->isRedirection()) {
            return;
        }

        $scope = (array) $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);

        if (!\in_array('storefront', $scope, true)) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return;
        }

        $content = $response->getContent();

        if (!\is_string($content) || $content === '') {
            return;
        }

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? $salesChannelId : null;

        try {
            $html = $content;

            if ($this->config->isDeliveryActive($salesChannelId)) {
                $html = $this->rewriter->rewriteImages($html, $this->config->excludedPatterns($salesChannelId));
            }

            if ($this->config->lazyLoadActive($salesChannelId)) {
                $html = $this->rewriter->addLazyLoading($html, $this->config->lazyLoadSkip($salesChannelId));
            }

            if ($html !== $content) {
                $response->setContent($html);
                $response->headers->remove('Content-Length');
            }
        } catch (\Throwable $e) {
            // Im Zweifel die Seite unverändert ausliefern
            $this->logger->warning('[ModulwerkWebp] ' . $e->getMessage());
        }
    }
}
