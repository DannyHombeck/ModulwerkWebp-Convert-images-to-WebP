<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

/**
 * Wandelt ein JPG oder PNG in WebP um. Bevorzugt Imagick, fällt auf GD
 * zurück. Beide Wege sind optional – welcher auf dem Server zur Verfügung
 * steht, zeigt die Übersicht im Admin.
 */
class ImageConverter
{
    public const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png'];

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
        $isPng = $mimeType === 'image/png';

        $result = $engine === 'imagick'
            ? $this->convertWithImagick($binary, $isPng, $quality, $lossless)
            : $this->convertWithGd($binary, $isPng, $quality, $lossless);

        // Gültige WebP-Datei? RIFF....WEBP
        if (\strlen($result) < 12 || substr($result, 0, 4) !== 'RIFF' || substr($result, 8, 4) !== 'WEBP') {
            throw new ConversionException(ConversionException::INVALID_RESULT);
        }

        return $result;
    }

    private function convertWithImagick(string $binary, bool $isPng, int $quality, bool $lossless): string
    {
        $image = new \Imagick();

        try {
            $image->readImageBlob($binary);

            if ($image->getNumberImages() > 1) {
                $frames = $image->coalesceImages();
                $image->clear();
                $image = $frames;
                $image->setIteratorIndex(0);
            }

            if (!$isPng) {
                $this->autoOrientImagick($image);
            }

            // Vor stripImage(), das auch das Farbprofil entfernt
            $this->toSrgbImagick($image);

            $image->stripImage();
            $image->setImageFormat('webp');
            $image->setOption('webp:method', '6');

            if ($lossless) {
                $image->setOption('webp:lossless', 'true');
            } else {
                $image->setImageCompressionQuality($quality);
            }

            if ($isPng) {
                $image->setOption('webp:alpha-quality', '100');
            }

            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
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

    private function convertWithGd(string $binary, bool $isPng, int $quality, bool $lossless): string
    {
        $this->assertGdColors($binary, $isPng);

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new ConversionException(ConversionException::GD_READ_FAILED);
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        if ($isPng) {
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
    private function assertGdColors(string $binary, bool $isPng): void
    {
        if (!$isPng) {
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
