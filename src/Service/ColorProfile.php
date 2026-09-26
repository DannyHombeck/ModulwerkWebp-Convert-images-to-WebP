<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

/**
 * Liest das eingebettete ICC-Farbprofil eines JPG oder PNG und erkennt,
 * ob es sich um sRGB handelt.
 *
 * Browser zeigen eine WebP-Datei ohne Profil als sRGB an. Bilder in einem
 * anderen Farbraum (Adobe RGB, Display P3 von Smartphones, CMYK) müssen
 * deshalb vor dem Entfernen der Metadaten nach sRGB umgerechnet werden,
 * sonst wirken sie blass oder farbverschoben.
 */
final class ColorProfile
{
    /** Namen, an denen ein sRGB-Profil zu erkennen ist ("c2", das kompakte sRGB-Profil von Facebook, wird zusätzlich geprüft) */
    private const SRGB_NAMES = ['srgb', 'iec61966', 'iec 61966'];

    private static ?string $srgb = null;

    /**
     * Das mitgelieferte sRGB-Profil (Ziel der Umrechnung mit Imagick).
     */
    public static function srgb(): string
    {
        if (self::$srgb === null) {
            $data = @file_get_contents(__DIR__ . '/../Resources/icc/sRGB-v2-micro.icc');

            if (!\is_string($data) || $data === '') {
                throw new ConversionException(ConversionException::COLOR_PROFILE, 'sRGB profile missing');
            }

            self::$srgb = $data;
        }

        return self::$srgb;
    }

    /**
     * @return string|null das ICC-Profil, null wenn keins eingebettet ist
     */
    public static function extract(string $binary): ?string
    {
        if (str_starts_with($binary, "\xFF\xD8")) {
            return self::fromJpeg($binary);
        }

        if (str_starts_with($binary, "\x89PNG\r\n\x1A\n")) {
            return self::fromPng($binary);
        }

        return null;
    }

    /**
     * Farbraum laut Profilkopf: "RGB ", "CMYK", "GRAY" ...
     */
    public static function colorSpace(string $icc): string
    {
        return \strlen($icc) >= 20 ? substr($icc, 16, 4) : '';
    }

    /**
     * true, wenn das Profil sRGB beschreibt oder die Farben ohne Profil
     * ohnehin richtig erscheinen (Graustufen, unlesbares Profil).
     */
    public static function isSrgb(string $icc): bool
    {
        if (\strlen($icc) < 132) {
            return true;
        }

        $space = self::colorSpace($icc);

        if ($space === 'GRAY') {
            return true;
        }

        if ($space !== 'RGB ') {
            return false;
        }

        $description = strtolower(self::description($icc));

        if ($description !== '') {
            if ($description === 'c2') {
                return true;
            }

            foreach (self::SRGB_NAMES as $name) {
                if (str_contains($description, $name)) {
                    return true;
                }
            }

            return false;
        }

        // Ohne lesbare Beschreibung im ganzen Profil suchen (auch UTF-16)
        return stripos($icc, 'sRGB') !== false || str_contains($icc, "s\0R\0G\0B");
    }

    /**
     * Name des Profils aus dem Tag "desc", z. B. "Display P3".
     */
    public static function description(string $icc): string
    {
        $length = \strlen($icc);

        if ($length < 132) {
            return '';
        }

        $count = unpack('N', substr($icc, 128, 4))[1];

        for ($i = 0; $i < min($count, 100); ++$i) {
            $entry = 132 + $i * 12;

            if ($entry + 12 > $length || substr($icc, $entry, 4) !== 'desc') {
                continue;
            }

            $offset = unpack('N', substr($icc, $entry + 4, 4))[1];
            $size = unpack('N', substr($icc, $entry + 8, 4))[1];

            if ($offset + $size > $length || $size < 12) {
                return '';
            }

            $tag = substr($icc, $offset, $size);
            $type = substr($tag, 0, 4);

            // ICC v2: ASCII-Text
            if ($type === 'desc') {
                $chars = unpack('N', substr($tag, 8, 4))[1];

                return self::clean(substr($tag, 12, max(0, min($chars, $size - 12))));
            }

            // ICC v4: mehrsprachig, UTF-16BE – erster Eintrag genügt
            if ($type === 'mluc' && $size >= 28) {
                $textLength = unpack('N', substr($tag, 20, 4))[1];
                $textOffset = unpack('N', substr($tag, 24, 4))[1];
                $text = substr($tag, $textOffset, $textLength);

                return self::clean(\function_exists('mb_convert_encoding')
                    ? (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16BE')
                    : str_replace("\0", '', $text));
            }

            return '';
        }

        return '';
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F]+/', '', $text));
    }

    private static function fromJpeg(string $binary): ?string
    {
        $length = \strlen($binary);
        $pos = 2;
        $chunks = [];

        while ($pos + 4 <= $length) {
            if ($binary[$pos] !== "\xFF") {
                break;
            }

            $marker = \ord($binary[$pos + 1]);

            // Füllbytes und Marker ohne Länge
            if ($marker === 0xFF) {
                ++$pos;

                continue;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                $pos += 2;

                continue;
            }

            // Ab den Bilddaten kommen keine Metadaten mehr
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $segment = unpack('n', substr($binary, $pos + 2, 2))[1];

            if ($segment < 2 || $pos + 2 + $segment > $length) {
                break;
            }

            // APP2 "ICC_PROFILE\0", Folgenummer, Anzahl, Daten
            if ($marker === 0xE2 && $segment > 16 && substr($binary, $pos + 4, 12) === "ICC_PROFILE\0") {
                $chunks[\ord($binary[$pos + 16])] = substr($binary, $pos + 18, $segment - 16);
            }

            $pos += 2 + $segment;
        }

        if ($chunks === []) {
            return null;
        }

        ksort($chunks);

        return implode('', $chunks);
    }

    private static function fromPng(string $binary): ?string
    {
        $length = \strlen($binary);
        $pos = 8;

        while ($pos + 8 <= $length) {
            $size = unpack('N', substr($binary, $pos, 4))[1];
            $type = substr($binary, $pos + 4, 4);

            if ($type === 'IDAT' || $type === 'IEND' || $pos + 12 + $size > $length) {
                break;
            }

            // Name, Nullbyte, Kompressionsart, zlib-Daten
            if ($type === 'iCCP') {
                $data = substr($binary, $pos + 8, $size);
                $nul = strpos($data, "\0");

                if ($nul === false) {
                    return null;
                }

                $profile = @gzuncompress(substr($data, $nul + 2));

                return \is_string($profile) && $profile !== '' ? $profile : null;
            }

            $pos += 12 + $size;
        }

        return null;
    }
}
