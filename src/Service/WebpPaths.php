<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

/**
 * Die WebP-Dateien liegen in einem eigenen Verzeichnis des öffentlichen
 * Dateisystems und spiegeln dort den Pfad des Originals:
 *
 *   media/ab/cd/ef/1712345678/bild.jpg
 *   -> modulwerk-webp/media/ab/cd/ef/1712345678/bild.jpg.webp
 *
 * Dadurch bleiben die Originale unberührt, und alles lässt sich mit einem
 * einzigen Verzeichnis wieder entfernen.
 */
final class WebpPaths
{
    public const DIRECTORY = 'modulwerk-webp';

    public static function forPath(string $path): string
    {
        return self::DIRECTORY . '/' . ltrim($path, '/') . '.webp';
    }

    public static function hash(string $path): string
    {
        return md5($path, true);
    }
}
