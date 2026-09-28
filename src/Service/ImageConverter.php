<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

/**
 * Wandelt JPG, PNG, GIF, BMP und TIFF in WebP um. Bevorzugt Imagick, fällt auf GD
 * zurück. Beide Wege sind optional – welcher auf dem Server zur Verfügung
 * steht, zeigt die Übersicht im Admin.
 */
class ImageConverter
{
    public const FORMAT_JPEG = 'jpeg';

    /**
     * WebP-Methode für die laufende Umwandlung: 6 = gründlichste (kleinste
     * Datei), 4 = deutlich schneller. Große Bilder und Animationen nehmen 4,
     * damit ein Schritt nicht in die Zeitlimits des Servers läuft.
     */
    private string $webpMethod = '6';

    public const FORMAT_PNG = 'png';
    public const FORMAT_GIF = 'gif';
    public const FORMAT_BMP = 'bmp';
    public const FORMAT_TIFF = 'tiff';

    /**
     * MIME-Typen je Format. Je nach Server meldet die Dateierkennung
     * dasselbe Format unterschiedlich (etwa BMP).
     *
     * @var array<string, list<string>>
     */
    public const MIME_TYPES = [
        self::FORMAT_JPEG => ['image/jpeg', 'image/jpg', 'image/pjpeg'],
        self::FORMAT_PNG => ['image/png'],
        self::FORMAT_GIF => ['image/gif'],
        self::FORMAT_BMP => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp', 'image/ms-bmp'],
        self::FORMAT_TIFF => ['image/tiff', 'image/tif', 'image/x-tiff'],
    ];

    /**
     * @param list<string> $formats eingeschaltete Formate
     *
     * @return list<string>
     */
    public static function mimeTypes(array $formats): array
    {
        $mimeTypes = [];

        foreach ($formats as $format) {
            array_push($mimeTypes, ...(self::MIME_TYPES[$format] ?? []));
        }

        return $mimeTypes;
    }

    /**
     * Format zum MIME-Typ; Unbekanntes wird wie JPG behandelt.
     */
    public static function formatOf(string $mimeType): string
    {
        $mimeType = strtolower($mimeType);

        foreach (self::MIME_TYPES as $format => $mimeTypes) {
            if (\in_array($mimeType, $mimeTypes, true)) {
                return $format;
            }
        }

        return self::FORMAT_JPEG;
    }

    /**
     * Obergrenze in Bildpunkten (Breite × Höhe × Einzelbilder). Größere
     * Bilder brauchen so viel Zeit und Speicher, dass der Server die Anfrage
     * abbrechen würde (502/504) – sie werden übersprungen.
     */
    public const MAX_PIXELS = 100_000_000;

    /** Bis zu dieser Größe die gründlichste, darüber die schnellere WebP-Methode */
    private const THOROUGH_MAX_PIXELS = 4_000_000;

    /**
     * true bei einem GIF mit mehr als einem Einzelbild.
     */
    public static function isAnimatedGif(string $binary): bool
    {
        return self::gifFrameCount($binary) > 1;
    }

    /**
     * Anzahl der Einzelbilder eines GIF (1 bei allen anderen Formaten).
     */
    public static function gifFrameCount(string $binary): int
    {
        if (!str_starts_with($binary, 'GIF8')) {
            return 1;
        }

        // Jedes Einzelbild beginnt mit einer Graphic Control Extension
        return max(1, (int) preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $binary));
    }

    /**
     * Bildpunkte insgesamt; 0, wenn die Größe nicht lesbar ist.
     */
    public static function pixelCount(string $binary): int
    {
        $info = @getimagesizefromstring($binary);

        if (!\is_array($info) || empty($info[0]) || empty($info[1])) {
            return 0;
        }

        return (int) $info[0] * (int) $info[1] * self::gifFrameCount($binary);
    }

    public function gdAvailable(): bool
    {
        return \function_exists('imagewebp') && \function_exists('imagecreatefromstring');
    }

    public function imagickAvailable(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return \in_array('WEBP', \Imagick::queryFormats('WEBP'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Welche Bibliothek tatsächlich benutzt wird: 'imagick', 'gd' oder null.
     */
    public function resolveEngine(string $preferred): ?string
    {
        if ($preferred === 'gd') {
            return $this->gdAvailable() ? 'gd' : ($this->imagickAvailable() ? 'imagick' : null);
        }

        if ($preferred === 'imagick') {
            return $this->imagickAvailable() ? 'imagick' : ($this->gdAvailable() ? 'gd' : null);
        }

        if ($this->imagickAvailable()) {
            return 'imagick';
        }

        return $this->gdAvailable() ? 'gd' : null;
    }

    /**
     * @throws ConversionException|\Throwable wenn die Umwandlung scheitert
     */
    public function convert(string $binary, string $mimeType, int $quality, bool $lossless, string $engine): string
    {
        $format = self::formatOf($mimeType);
        $pixels = self::pixelCount($binary);

        if ($pixels > self::MAX_PIXELS) {
            throw new ConversionException(ConversionException::PIXEL_LIMIT, (string) round($pixels / 1_000_000) . ' MP');
        }

        $this->webpMethod = $pixels > 0 && $pixels <= self::THOROUGH_MAX_PIXELS ? '6' : '4';

        $result = $engine === 'imagick'
            ? $this->convertWithImagick($binary, $format, $quality, $lossless)
            : $this->convertWithGd($binary, $format, $quality, $lossless);

        // Gültige WebP-Datei? RIFF....WEBP
        if (\strlen($result) < 12 || substr($result, 0, 4) !== 'RIFF' || substr($result, 8, 4) !== 'WEBP') {
            throw new ConversionException(ConversionException::INVALID_RESULT);
        }

        return $result;
    }

    private function convertWithImagick(string $binary, string $format, int $quality, bool $lossless): string
    {
        $image = new \Imagick();

        try {
            $image->readImageBlob($binary);

            if ($format === self::FORMAT_GIF && $image->getNumberImages() > 1) {
                $animation = $this->animatedWebpImagick($image, $quality, $lossless);
                $image = $animation['image'];

                return $animation['blob'];
            }

            // Mehrseitige Dateien (etwa TIFF): nur das erste Bild
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
                $first = $image->getImage();
                $image->clear();
                $image = $first;
            }

            // EXIF-/TIFF-Ausrichtung gibt es nur bei JPG und TIFF
            if ($format === self::FORMAT_JPEG || $format === self::FORMAT_TIFF) {
                $this->autoOrientImagick($image);
            }

            // Vor stripImage(), das auch das Farbprofil entfernt
            $this->toSrgbImagick($image);

            $image->stripImage();
            $image->setImageFormat('webp');
            $image->setOption('webp:method', $this->webpMethod);

            if ($lossless) {
                $image->setOption('webp:lossless', 'true');
            } else {
                $image->setImageCompressionQuality($quality);
            }

            if ($format !== self::FORMAT_JPEG) {
                $image->setOption('webp:alpha-quality', '100');
            }

            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
    }

    /**
     * Animiertes GIF als animiertes WebP. Braucht ImageMagick mit
     * WebP-Animation (libwebpmux); fehlt sie, wird das Bild übersprungen,
     * statt nur das erste Einzelbild auszuliefern.
     *
     * @return array{image: \Imagick, blob: string}
     */
    private function animatedWebpImagick(\Imagick $image, int $quality, bool $lossless): array
    {
        $frames = $image->coalesceImages();
        $image->clear();

        $frames->setOption('webp:method', $this->webpMethod);

        if ($lossless) {
            $frames->setOption('webp:lossless', 'true');
        }

        foreach ($frames as $frame) {
            $frame->stripImage();
            $frame->setImageFormat('webp');

            if (!$lossless) {
                $frame->setImageCompressionQuality($quality);
            }
        }

        $frames->setIteratorIndex(0);
        $frames->setImageFormat('webp');
        $blob = $frames->getImagesBlob();

        // Animiertes WebP enthält einen ANIM-Block im Kopf
        if (!str_contains(substr($blob, 0, 64), 'ANIM')) {
            throw new ConversionException(ConversionException::FORMAT_UNSUPPORTED, 'GIF-Animation');
        }

        return ['image' => $frames, 'blob' => $blob];
    }

    /**
     * Rechnet Bilder mit einem anderen Farbraum als sRGB (Adobe RGB,
     * Display P3, CMYK) nach sRGB um. Ohne Profil zeigen Browser WebP als
     * sRGB an; ohne Umrechnung wären die Farben verschoben.
     */
    private function toSrgbImagick(\Imagick $image): void
    {
        try {
            $profiles = $image->getImageProfiles('icc', true);
        } catch (\Throwable) {
            $profiles = [];
        }

        $icc = isset($profiles['icc']) && \is_string($profiles['icc']) ? $profiles['icc'] : null;
        $cmyk = $image->getImageColorspace() === \Imagick::COLORSPACE_CMYK;

        if ($icc !== null && !ColorProfile::isSrgb($icc)) {
            try {
                // Mit vorhandenem Quellprofil rechnet ImageMagick (LCMS) farbtreu um
                $image->profileImage('icc', ColorProfile::srgb());

                return;
            } catch (\Throwable) {
                // ImageMagick ohne LCMS: lieber das Original behalten als falsche Farben
                throw new ConversionException(
                    ConversionException::COLOR_PROFILE,
                    ColorProfile::description($icc) ?: trim(ColorProfile::colorSpace($icc))
                );
            }
        }

        if ($cmyk) {
            // CMYK ohne Profil: nur näherungsweise möglich, aber besser als gar nicht
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }
    }

    private function autoOrientImagick(\Imagick $image): void
    {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();

            return;
        }

        switch ($image->getImageOrientation()) {
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
        }

        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    private function convertWithGd(string $binary, string $format, int $quality, bool $lossless): string
    {
        // GD kennt kein TIFF und liest bei GIF nur das erste Einzelbild
        if ($format === self::FORMAT_TIFF) {
            throw new ConversionException(ConversionException::FORMAT_UNSUPPORTED, 'TIFF');
        }

        if ($format === self::FORMAT_GIF && self::isAnimatedGif($binary)) {
            throw new ConversionException(ConversionException::FORMAT_UNSUPPORTED, 'GIF-Animation');
        }

        $keepAlpha = $format !== self::FORMAT_JPEG;
        $this->assertGdColors($binary, $keepAlpha);

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            // GD liest nicht jede BMP-Variante (z. B. 32 Bit mit Transparenz)
            if (str_starts_with($binary, 'BM')) {
                throw new ConversionException(ConversionException::FORMAT_UNSUPPORTED, 'BMP');
            }

            throw new ConversionException(ConversionException::GD_READ_FAILED);
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        if ($keepAlpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        } else {
            $image = $this->autoOrientGd($image, $binary);
        }

        // GD kennt ab PHP 8.1 IMG_WEBP_LOSSLESS (Qualitaet 101), sonst verlustbehaftet
        $gdQuality = $lossless && \defined('IMG_WEBP_LOSSLESS') ? \IMG_WEBP_LOSSLESS : $quality;

        ob_start();
        $ok = imagewebp($image, null, $gdQuality);
        $result = (string) ob_get_clean();

        // Seit PHP 8 gibt PHP das Bild selbst frei, imagedestroy() ist ab 8.5 veraltet
        unset($image);

        if (!$ok) {
            throw new ConversionException(ConversionException::GD_WRITE_FAILED);
        }

        return $result;
    }

    /**
     * GD kennt keine Farbprofile und rechnet CMYK nicht richtig um. Solche
     * Bilder werden übersprungen, die Storefront zeigt dann das Original
     * mit richtigen Farben. Mit Imagick werden sie umgerechnet.
     */
    private function assertGdColors(string $binary, bool $keepAlpha): void
    {
        if (!$keepAlpha) {
            $info = @getimagesizefromstring($binary);

            if (\is_array($info) && (int) ($info['channels'] ?? 3) === 4) {
                throw new ConversionException(ConversionException::COLOR_PROFILE, 'CMYK');
            }
        }

        $icc = ColorProfile::extract($binary);

        if ($icc !== null && !ColorProfile::isSrgb($icc)) {
            throw new ConversionException(
                ConversionException::COLOR_PROFILE,
                ColorProfile::description($icc) ?: trim(ColorProfile::colorSpace($icc))
            );
        }
    }

    /**
     * Fotos aus Kameras und Handys sind oft nur per EXIF gedreht. Das WebP
     * trägt diese Angabe nicht mehr, deshalb wird hier fest gedreht.
     */
    private function autoOrientGd(\GdImage $image, string $binary): \GdImage
    {
        if (!\function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($binary));
        $orientation = \is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            2 => $this->flip($image, \IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flip($image, \IMG_FLIP_VERTICAL),
            5 => $this->flip(imagerotate($image, -90, 0), \IMG_FLIP_HORIZONTAL),
            6 => imagerotate($image, -90, 0),
            7 => $this->flip(imagerotate($image, 90, 0), \IMG_FLIP_HORIZONTAL),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated instanceof \GdImage) {
            return $rotated;
        }

        return $image;
    }

    private function flip(\GdImage|false $image, int $mode): \GdImage|false
    {
        if ($image instanceof \GdImage) {
            imageflip($image, $mode);
        }

        return $image;
    }
}
