<?php declare(strict_types=1);

/*
 * Seit Shopware 6.8 (Symfony 8) werden services.xml und routes.xml nicht
 * mehr geladen. Dieselben Definitionen in PHP-Schreibweise funktionieren
 * auch mit Shopware 6.7.
 */

use ModulwerkWebp\Command\ClearCommand;
use ModulwerkWebp\Command\ConvertCommand;
use ModulwerkWebp\Command\StatusCommand;
use ModulwerkWebp\Controller\WebpApiController;
use ModulwerkWebp\ScheduledTask\ConvertTask;
use ModulwerkWebp\ScheduledTask\ConvertTaskHandler;
use ModulwerkWebp\Service\ConversionService;
use ModulwerkWebp\Service\HtmlRewriter;
use ModulwerkWebp\Service\ImageConverter;
use ModulwerkWebp\Service\MediaLibraryService;
use ModulwerkWebp\Service\ScheduledTaskConfigurator;
use ModulwerkWebp\Service\WebpCacheService;
use ModulwerkWebp\Service\WebpConfig;
use ModulwerkWebp\Subscriber\CacheFlushSubscriber;
use ModulwerkWebp\Subscriber\MediaSubscriber;
use ModulwerkWebp\Subscriber\StorefrontResponseSubscriber;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Cache\CacheClearer;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
            ->autowire(false)
            ->autoconfigure(false)
            ->private();

    /* Einstellungen */
    $services->set(WebpConfig::class)
        ->args([service(SystemConfigService::class)]);

    /* Umwandlung */
    $services->set(ImageConverter::class);

    $services->set(MediaLibraryService::class)
        ->args([
            service(Connection::class),
            service('media.repository'),
            service('media_folder.repository'),
            service(WebpConfig::class),
        ]);

    $services->set(WebpCacheService::class)
        ->args([
            service(CacheClearer::class),
            service(WebpConfig::class),
            service('logger'),
        ]);

    $services->set(ScheduledTaskConfigurator::class)
        ->args([
            service(Connection::class),
            service(WebpConfig::class),
        ]);

    $services->set(ConversionService::class)
        ->public()
        ->args([
            service(Connection::class),
            service('shopware.filesystem.public'),
            service(ImageConverter::class),
            service(WebpConfig::class),
            service('logger'),
            service(MediaLibraryService::class),
            service(WebpCacheService::class),
        ]);

    $services->set(HtmlRewriter::class)
        ->args([service(ConversionService::class)]);

    /* Storefront-Ausgabe umschreiben */
    $services->set(StorefrontResponseSubscriber::class)
        ->args([
            service(HtmlRewriter::class),
            service(WebpConfig::class),
            service('logger'),
        ])
        ->tag('kernel.event_subscriber');

    /* Medien löschen / ersetzen / Thumbnails neu / Einstellungen geändert */
    $services->set(MediaSubscriber::class)
        ->args([
            service(ConversionService::class),
            service(Connection::class),
            service('logger'),
            service(ScheduledTaskConfigurator::class),
        ])
        ->tag('kernel.event_subscriber');

    /* Cache nach der Verarbeitung leeren */
    $services->set(CacheFlushSubscriber::class)
        ->args([service(WebpCacheService::class)])
        ->tag('kernel.event_subscriber');

    /* Geplante Aufgabe */
    $services->set(ConvertTask::class)
        ->tag('shopware.scheduled.task');

    $services->set(ConvertTaskHandler::class)
        ->args([
            service('scheduled_task.repository'),
            service('logger'),
            service(ConversionService::class),
            service(WebpConfig::class),
            service(WebpCacheService::class),
            service(ScheduledTaskConfigurator::class),
        ])
        ->tag('messenger.message_handler');

    /* CLI */
    $services->set(ConvertCommand::class)
        ->args([
            service(ConversionService::class),
            service(WebpConfig::class),
            service(WebpCacheService::class),
        ])
        ->tag('console.command');

    $services->set(StatusCommand::class)
        ->args([service(ConversionService::class)])
        ->tag('console.command');

    $services->set(ClearCommand::class)
        ->args([
            service(ConversionService::class),
            service(WebpCacheService::class),
        ])
        ->tag('console.command');

    /* Admin-API */
    $services->set(WebpApiController::class)
        ->public()
        ->args([
            service(ConversionService::class),
            service(WebpConfig::class),
            service(WebpCacheService::class),
        ])
        ->call('setContainer', [service('service_container')])
        ->tag('controller.service_arguments');
};
