<?php

declare(strict_types=1);

namespace AgentAutomation;

use DateTimeImmutable;
use DateTimeZone;

final class CaseCalendar
{
    public static function validate(array $input): array
    {
        $zone = $input['zone'] ?? 'UTC';
        if (!is_string($zone) || !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw Problem::invalid('Invalid calendar.zone.');
        }
        $opens = Values::number($input['opens'] ?? 9, 'calendar.opens', 0, 23);
        $closes = Values::number($input['closes'] ?? 17, 'calendar.closes', $opens + 1, 24);
        $days = $input['weekdays'] ?? [1, 2, 3, 4, 5];
        $holidays = $input['holidays'] ?? [];
        if (!is_array($days) || !array_is_list($days) || $days === [] || count($days) > 7 ||
            !is_array($holidays) || !array_is_list($holidays) || count($holidays) > 366) {
            throw Problem::invalid('Invalid business days or holidays.');
        }
        foreach ($days as $day) {
            Values::number($day, 'calendar.weekdays', 1, 7);
        }
        foreach ($holidays as $holiday) {
            Values::utcSecond(Values::text($holiday, 'holiday', 10) . 'T00:00:00Z', 'calendar.holidays');
        }
        $days = array_values(array_unique($days));
        $holidays = array_values(array_unique($holidays));
        sort($days);
        sort($holidays);
        return ['zone' => $zone, 'opens' => $opens, 'closes' => $closes, 'weekdays' => $days, 'holidays' => $holidays];
    }

    public static function deadline(DateTimeImmutable $at, array $calendar, int $minutes): string
    {
        $cursor = $at->setTimezone(new DateTimeZone($calendar['zone']));
        $day = $cursor->setTime(0, 0);
        $remaining = $minutes * 60;
        while (true) {
            if (in_array((int) $day->format('N'), $calendar['weekdays'], true) &&
                !in_array($day->format('Y-m-d'), $calendar['holidays'], true)) {
                $start = max($cursor->getTimestamp(), $day->setTime($calendar['opens'], 0)->getTimestamp());
                $end = $day->setTime($calendar['closes'], 0)->getTimestamp();
                if ($end > $start && $end - $start >= $remaining) {
                    return gmdate('Y-m-d H:i:s', $start + $remaining);
                }
                $remaining -= max(0, $end - $start);
            }
            $day = $day->modify('+1 day')->setTime(0, 0);
        }
    }
}
