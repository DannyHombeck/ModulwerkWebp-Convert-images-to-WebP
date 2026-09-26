<?php declare(strict_types=1);

namespace ModulwerkWebp\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ConvertTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'modulwerk_webp.convert';
    }

    /**
     * Entspricht dem Standard der Einstellung (1440 Minuten = 24 Stunden).
     * Weicht die Einstellung ab, gleicht ScheduledTaskConfigurator den
     * Abstand an.
     */
    public static function getDefaultInterval(): int
    {
        return 86400;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
