<?php declare(strict_types=1);

namespace ModulwerkWebp\Service;

use ModulwerkWebp\ScheduledTask\ConvertTask;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskDefinition;

/**
 * Überträgt die Einstellungen auf den Eintrag in der Aufgabenverwaltung
 * (Einstellungen → System → Aufgaben): entweder alle X Minuten oder
 * einmal täglich zu einer festen Uhrzeit, und ob die Aufgabe überhaupt
 * eingeplant wird.
 */
class ScheduledTaskConfigurator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WebpConfig $config
    ) {
    }

    /**
     * Abstand in Sekunden, der laut Einstellungen gelten soll.
     *
     * Bei fester Uhrzeit läuft die Aufgabe alle fünf Minuten und prüft
     * nur, ob der heutige Termin schon erreicht ist. Das ist
     * zuverlässiger, als den nächsten Termin selbst zu setzen: Shopware
     * berechnet ihn nach jedem Lauf ohnehin neu.
     */
    public function expectedInterval(): int
    {
        return $this->config->taskMode() === 'daily' ? 300 : $this->config->taskIntervalMinutes() * 60;
    }

    /**
     * Gleicht nur den Abstand an, ohne Status oder Termin anzufassen.
     * Läuft zu Beginn jeder Aufgabe, damit der Abstand auch dann stimmt,
     * wenn die Einstellungen nie gespeichert wurden (etwa direkt nach der
     * Installation) oder am Admin vorbei geändert wurden.
     */
    public function syncInterval(): void
    {
        $interval = $this->expectedInterval();

        $this->connection->executeStatement(
            'UPDATE `scheduled_task` SET `run_interval` = :interval WHERE `name` = :name AND `run_interval` <> :interval',
            ['interval' => $interval, 'name' => ConvertTask::getTaskName()]
        );
    }

    public function apply(): void
    {
        $interval = $this->expectedInterval();
        $active = $this->config->scheduledTaskActive();

        $current = $this->connection->fetchAssociative(
            'SELECT `run_interval`, `status` FROM `scheduled_task` WHERE `name` = :name',
            ['name' => ConvertTask::getTaskName()]
        );

        if ($current === false) {
            return;
        }

        $status = $active
            ? ($current['status'] === ScheduledTaskDefinition::STATUS_INACTIVE ? ScheduledTaskDefinition::STATUS_SCHEDULED : $current['status'])
            : ScheduledTaskDefinition::STATUS_INACTIVE;

        $next = $this->nextExecution($interval);

        /*
         * Der nächste Termin wird nur vorgezogen, nie nach hinten
         * geschoben – ein längerer Abstand soll einen geplanten Lauf nicht
         * verzögern.
         */
        $sql = 'UPDATE `scheduled_task` SET `run_interval` = :interval, `status` = :status';
        $sql .= ', `next_execution_time` = IF(:active = 1, LEAST(`next_execution_time`, :next), `next_execution_time`)';
        $sql .= ' WHERE `name` = :name';

        $this->connection->executeStatement($sql, [
            'interval' => $interval,
            'status' => $status,
            'active' => $active ? 1 : 0,
            'next' => $next,
            'name' => ConvertTask::getTaskName(),
        ]);
    }

    /**
     * Nächster Lauf, gespeichert wie in Shopware üblich in UTC.
     */
    private function nextExecution(int $interval): string
    {
        return (new \DateTimeImmutable('now'))
            ->modify('+' . $interval . ' seconds')
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    /**
     * Ist der heutige Termin des täglichen Laufs erreicht und heute noch
     * nicht gelaufen?
     */
    public function isDailyRunDue(): bool
    {
        [$hour, $minute] = $this->config->taskTime();
        $now = new \DateTimeImmutable('now');
        $today = $now->format('Y-m-d');

        if ($this->config->lastDailyRun() === $today) {
            return false;
        }

        return $now >= $now->setTime($hour, $minute);
    }

    public function markDailyRun(): void
    {
        $this->config->setLastDailyRun((new \DateTimeImmutable('now'))->format('Y-m-d'));
    }

    /**
     * Uhrzeit des nächsten Laufs für die Anzeige im Admin (ISO, UTC).
     */
    public function getNextExecution(): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT `next_execution_time` FROM `scheduled_task` WHERE `name` = :name AND `status` <> :inactive',
            ['name' => ConvertTask::getTaskName(), 'inactive' => ScheduledTaskDefinition::STATUS_INACTIVE]
        );

        return \is_string($value) ? str_replace(' ', 'T', $value) . 'Z' : null;
    }
}
