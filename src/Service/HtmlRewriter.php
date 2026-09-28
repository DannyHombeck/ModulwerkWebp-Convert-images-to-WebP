<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

/**
 * Schreibt in fertig gerendertem Storefront-HTML die Bildpfade auf die
 * WebP-Fassung um.
 *
 * Gesucht wird nach Medienpfaden (media/... und thumbnail/...), egal aus
 * welchem Template sie stammen – Produktbilder, Erlebniswelten, Logo,
 * Slider oder Theme-Erweiterungen. Umgeschrieben wird nur, was laut
 * Datenbank wirklich als WebP vorliegt. Mails, Feeds und die API bleiben
 * unberührt, weil nur Storefront-Antworten durchlaufen.
 */
class HtmlRewriter
{
    /** Pfad mit normalen Schrägstrichen, z. B. in src und srcset */
    private const PATTERN_PLAIN = '~(?<![A-Za-z0-9_\-])(?:media|thumbnail)/[^\s"\'<>()?,\\\\]+?\.(?:jpe?g|png|gif|bmp|tiff?)(?![A-Za-z0-9_.\-])~i';

    /** Derselbe Pfad in JSON-Optionen (data-*-options), dort als \/ maskiert */
    private const PATTERN_ESCAPED = '~(?<![A-Za-z0-9_\-])(?:media|thumbnail)\\\\/(?:[^\s"\'<>()?,\\\\]|\\\\/)+?\.(?:jpe?g|png|gif|bmp|tiff?)(?![A-Za-z0-9_.\-])~i';

    public function __construct(private readonly ConversionService $conversionService)
    {
    }

    /**
     * @param list<string> $excluded Teilstrings, die nie umgeschrieben werden
     */
    public function rewriteImages(string $html, array $excluded = []): string
    {
        if (stripos($html, '.jp') === false && stripos($html, '.png') === false) {
            return $html;
        }

        preg_match_all(self::PATTERN_PLAIN, $html, $plain);
        preg_match_all(self::PATTERN_ESCAPED, $html, $escaped);

        $candidates = [];

        foreach ($plain[0] as $match) {
            $candidates[$match] = $this->lookupKeys($match);
        }

        foreach ($escaped[0] as $match) {
            $candidates[$match] = $this->lookupKeys(str_replace('\\/', '/', $match));
        }

        if ($candidates === []) {
            return $html;
        }

        $converted = $this->conversionService->filterConvertedPaths(array_merge(...array_values($candidates)));

        if ($converted === []) {
            return $html;
        }

        $replace = [];

        foreach ($candidates as $match => $keys) {
            if (!$this->isConverted($keys, $converted) || $this->isExcluded($match, $excluded)) {
                continue;
            }

            $isEscaped = str_contains($match, '\\/');
            $prefix = $isEscaped ? WebpPaths::DIRECTORY . '\\/' : WebpPaths::DIRECTORY . '/';
            $replace[$match] = $prefix . $match . '.webp';
        }

        if ($replace === []) {
            return $html;
        }

        $callback = static fn (array $m): string => $replace[$m[0]] ?? $m[0];

        $html = (string) preg_replace_callback(self::PATTERN_PLAIN, $callback, $html);

        return (string) preg_replace_callback(self::PATTERN_ESCAPED, $callback, $html);
    }

    /**
     * Setzt loading="lazy" und decoding="async" ab dem ($skip + 1)-ten Bild.
     * Die ersten Bilder bleiben unberührt, damit das LCP-Bild nicht leidet.
     * Bilder mit der Klasse "no-lazyload" werden ausgelassen.
     */
    public function addLazyLoading(string $html, int $skip): string
    {
        $index = 0;

        return (string) preg_replace_callback('~<img\b[^>]*>~i', static function (array $m) use (&$index, $skip): string {
            $tag = $m[0];
            ++$index;

            if ($index <= $skip || preg_match('~class\s*=\s*["\'][^"\']*\bno-lazyload\b~i', $tag)) {
                return $tag;
            }

            if (preg_match('~\sfetchpriority\s*=\s*["\']?high~i', $tag)) {
                return $tag;
            }

            if (preg_match('~\sloading\s*=\s*["\']?[a-z]*["\']?~i', $tag)) {
                $tag = (string) preg_replace('~(\s)loading\s*=\s*(["\']?)[a-z]*\2~i', '$1loading="lazy"', $tag, 1);
            } else {
                $tag = (string) preg_replace('~^<img\b~i', '<img loading="lazy"', $tag, 1);
            }

            if (!preg_match('~\sdecoding\s*=~i', $tag)) {
                $tag = (string) preg_replace('~^<img\b~i', '<img decoding="async"', $tag, 1);
            }

            return $tag;
        }, $html);
    }

    /**
     * Die URL kann prozentkodiert sein (sw_encode_url), der Pfad in der
     * Datenbank ist es nicht. Deshalb wird mit beiden Varianten gesucht.
     *
     * @return list<string>
     */
    private function lookupKeys(string $path): array
    {
        $decoded = rawurldecode($path);

        return $decoded === $path ? [$path] : [$path, $decoded];
    }

    /**
     * @param list<string> $keys
     * @param array<string, true> $converted
     */
    private function isConverted(array $keys, array $converted): bool
    {
        foreach ($keys as $key) {
            if (isset($converted[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $excluded
     */
    private function isExcluded(string $match, array $excluded): bool
    {
        foreach ($excluded as $needle) {
            if (stripos($match, $needle) !== false || stripos(str_replace('\\/', '/', $match), $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
