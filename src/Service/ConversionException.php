<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

/**
 * Fehler mit sprachneutralem Code. Gespeichert wird "code|detail", die
 * Administration übersetzt den Code über ihre Snippets (de-DE / en-GB).
 */
class ConversionException extends \RuntimeException
{
    public const ABORTED = 'aborted';
    public const READ_FAILED = 'read_failed';
    public const NOT_SMALLER = 'not_smaller';
    public const TOO_LARGE = 'too_large';
    public const INVALID_RESULT = 'invalid_result';
    public const GD_READ_FAILED = 'gd_read_failed';
    public const GD_WRITE_FAILED = 'gd_write_failed';
    public const CONVERT_FAILED = 'convert_failed';
    public const COLOR_PROFILE = 'color_profile';

    public function __construct(
        private readonly string $messageCode,
        private readonly string $detail = ''
    ) {
        parent::__construct($messageCode . ($detail !== '' ? ': ' . $detail : ''));
    }

    public function getMessageCode(): string
    {
        return $this->messageCode;
    }

    public function toStoredMessage(): string
    {
        return self::encode($this->messageCode, $this->detail);
    }

    public static function encode(string $code, string $detail = ''): string
    {
        return $detail === '' ? $code : $code . '|' . $detail;
    }
}
