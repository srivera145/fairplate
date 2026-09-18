<?php

namespace Keel\App\Services;

/**
 * The shape of restaurants.hours, and the questions asked of it.
 *
 * A kitchen that closes between lunch and dinner is the normal case, not an
 * edge case, so a day is a list of ranges rather than one open and one close.
 * Holiday closures are whole dates, kept separately, because "closed on
 * Thanksgiving" is not a fact about Thursdays.
 *
 * The stored shape:
 *
 *   {
 *     "days": { "mon": [{"open": "11:00", "close": "14:00"},
 *                       {"open": "17:00", "close": "22:00"}], ... },
 *     "closures": [{"date": "2026-11-26", "label": "Thanksgiving"}]
 *   }
 *
 * The phase-2 seeder wrote the simpler {"mon": {"open": .., "close": ..}}, and
 * normalize() still reads it, so seeded restaurants do not need re-seeding to
 * open the editor. Everything written from here on is the shape above.
 *
 * Times are the restaurant's local wall clock in America/New_York, which is the
 * only way a human can edit them. The UTC conversion happens at the comparison,
 * not in storage.
 */
class RestaurantHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DAY_LABELS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /** How many ranges one day may hold. Three covers breakfast, lunch, dinner. */
    public const MAX_RANGES_PER_DAY = 3;

    public const ZONE = 'America/New_York';

    /**
     * Whatever was stored, as the shape above.
     *
     * @return array{days: array<string, list<array{open: string, close: string}>>, closures: list<array{date: string, label: string}>}
     */
    public static function normalize(mixed $hours): array
    {
        if (is_string($hours)) {
            $hours = json_decode($hours, true);
        }

        if (!is_array($hours)) {
            $hours = [];
        }

        // The phase-2 shape: seven day keys at the top level, each one range.
        $days = is_array($hours['days'] ?? null) ? $hours['days'] : $hours;

        $normalized = [];

        foreach (self::DAYS as $day) {
            $normalized[$day] = self::normalizeDay($days[$day] ?? null);
        }

        return [
            'days' => $normalized,
            'closures' => self::normalizeClosures($hours['closures'] ?? null),
        ];
    }

    /**
     * @return string JSON ready for the hours column
     */
    public static function encode(array $hours): string
    {
        return (string) json_encode(self::normalize($hours), JSON_THROW_ON_ERROR);
    }

    /**
     * Is the kitchen open at this moment?
     *
     * A closure date wins over the weekly hours, and a range that ends before
     * it starts is treated as running past midnight, because "22:00 to 02:00"
     * is what a late kitchen actually types.
     */
    public static function isOpenAt(mixed $hours, ?\DateTimeImmutable $at = null): bool
    {
        $hours = self::normalize($hours);
        $local = ($at ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone(self::ZONE));

        if (self::isClosedOn($hours, $local->format('Y-m-d'))) {
            return false;
        }

        $minutes = ((int) $local->format('G') * 60) + (int) $local->format('i');
        $today = self::DAYS[((int) $local->format('N')) - 1];
        $yesterday = self::DAYS[(((int) $local->format('N')) + 5) % 7];

        foreach ($hours['days'][$today] ?? [] as $range) {
            [$open, $close] = self::rangeMinutes($range);

            if ($close > $open && $minutes >= $open && $minutes < $close) {
                return true;
            }

            // Runs past midnight: the part of it that falls today.
            if ($close <= $open && $minutes >= $open) {
                return true;
            }
        }

        // The tail of a range that opened yesterday and has not closed yet.
        if (!self::isClosedOn($hours, $local->modify('-1 day')->format('Y-m-d'))) {
            foreach ($hours['days'][$yesterday] ?? [] as $range) {
                [$open, $close] = self::rangeMinutes($range);

                if ($close <= $open && $minutes < $close) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * When the kitchen next opens, or null if it never does within a week.
     *
     * A closed card has to say more than "closed", and the only honest way to
     * say when is to walk the week forward looking for the first range that
     * starts after now. Seven days is the whole cycle, so a kitchen that opens
     * at all is found, and one that opens on no day is correctly reported as
     * having no next opening rather than being given a made-up one.
     */
    public static function nextOpeningAt(mixed $hours, ?\DateTimeImmutable $at = null): ?\DateTimeImmutable
    {
        $hours = self::normalize($hours);
        $zone = new \DateTimeZone(self::ZONE);
        $local = ($at ?? new \DateTimeImmutable('now'))->setTimezone($zone);

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $local->modify("+{$offset} day");
            $date = $day->format('Y-m-d');

            if (self::isClosedOn($hours, $date)) {
                continue;
            }

            $key = self::DAYS[((int) $day->format('N')) - 1];

            foreach ($hours['days'][$key] ?? [] as $range) {
                $opensAt = \DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $date . ' ' . $range['open'] . ':00',
                    $zone
                );

                if ($opensAt !== false && $opensAt > $local) {
                    return $opensAt;
                }
            }
        }

        return null;
    }

    /**
     * "Closed — opens at 11:00am", or "Closed — opens Monday 11:00am" when the
     * next opening is not today.
     */
    public static function nextOpeningLabel(mixed $hours, ?\DateTimeImmutable $at = null): string
    {
        $opensAt = self::nextOpeningAt($hours, $at);

        if ($opensAt === null) {
            return 'Closed';
        }

        $local = ($at ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone(self::ZONE));
        $clock = self::clockLabel($opensAt->format('H:i'));

        if ($opensAt->format('Y-m-d') === $local->format('Y-m-d')) {
            return 'Closed — opens at ' . $clock;
        }

        if ($opensAt->format('Y-m-d') === $local->modify('+1 day')->format('Y-m-d')) {
            return 'Closed — opens tomorrow ' . $clock;
        }

        return 'Closed — opens ' . $opensAt->format('l') . ' ' . $clock;
    }

    public static function isClosedOn(array $hours, string $date): bool
    {
        foreach ($hours['closures'] ?? [] as $closure) {
            if (($closure['date'] ?? '') === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * A day's ranges as one readable line, for the board and the customer menu.
     */
    public static function describeDay(array $ranges): string
    {
        if ($ranges === []) {
            return 'Closed';
        }

        $parts = [];

        foreach ($ranges as $range) {
            $parts[] = self::clockLabel($range['open']) . '–' . self::clockLabel($range['close']);
        }

        return implode(', ', $parts);
    }

    /**
     * "17:00" as "5:00pm", which is how a menu reads it back.
     */
    public static function clockLabel(string $time): string
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '00');
        $hour = (int) $hour;
        $suffix = $hour < 12 ? 'am' : 'pm';
        $display = $hour % 12;

        return ($display === 0 ? 12 : $display) . ':' . $minute . $suffix;
    }

    /**
     * Builds the stored shape from the posted form.
     *
     * The form sends parallel open/close arrays per day and a pair of closure
     * arrays. A row with either side blank is dropped rather than rejected: an
     * empty range slot is how someone removes one.
     *
     * @param array $input the request body
     * @return array{hours: array, errors: list<string>}
     */
    public static function fromForm(array $input): array
    {
        $errors = [];
        $days = [];

        foreach (self::DAYS as $day) {
            $opens = (array) ($input['open'][$day] ?? []);
            $closes = (array) ($input['close'][$day] ?? []);
            $ranges = [];

            foreach ($opens as $index => $open) {
                $open = trim((string) $open);
                $close = trim((string) ($closes[$index] ?? ''));

                if ($open === '' && $close === '') {
                    continue;
                }

                if (!self::isTime($open) || !self::isTime($close)) {
                    $errors[] = self::DAY_LABELS[$day] . ': both an opening and a closing time are needed.';
                    continue;
                }

                if ($open === $close) {
                    $errors[] = self::DAY_LABELS[$day] . ': a range cannot open and close at the same minute.';
                    continue;
                }

                $ranges[] = ['open' => self::padTime($open), 'close' => self::padTime($close)];
            }

            if (count($ranges) > self::MAX_RANGES_PER_DAY) {
                $errors[] = self::DAY_LABELS[$day] . ': at most ' . self::MAX_RANGES_PER_DAY . ' ranges.';
                $ranges = array_slice($ranges, 0, self::MAX_RANGES_PER_DAY);
            }

            $days[$day] = $ranges;
        }

        $closures = [];
        $dates = (array) ($input['closure_date'] ?? []);
        $labels = (array) ($input['closure_label'] ?? []);

        foreach ($dates as $index => $date) {
            $date = trim((string) $date);

            if ($date === '') {
                continue;
            }

            if (!self::isDate($date)) {
                $errors[] = "\"{$date}\" is not a date. Use YYYY-MM-DD.";
                continue;
            }

            $closures[] = [
                'date' => $date,
                'label' => trim((string) ($labels[$index] ?? '')),
            ];
        }

        return [
            'hours' => self::normalize(['days' => $days, 'closures' => $closures]),
            'errors' => $errors,
        ];
    }

    /**
     * @return list<array{open: string, close: string}>
     */
    private static function normalizeDay(mixed $day): array
    {
        if (!is_array($day) || $day === []) {
            return [];
        }

        // The phase-2 shape: one range, as a single object.
        if (isset($day['open']) || isset($day['close'])) {
            $day = [$day];
        }

        $ranges = [];

        foreach ($day as $range) {
            if (!is_array($range)) {
                continue;
            }

            $open = self::padTime(trim((string) ($range['open'] ?? '')));
            $close = self::padTime(trim((string) ($range['close'] ?? '')));

            if (!self::isTime($open) || !self::isTime($close) || $open === $close) {
                continue;
            }

            $ranges[] = ['open' => $open, 'close' => $close];

            if (count($ranges) >= self::MAX_RANGES_PER_DAY) {
                break;
            }
        }

        usort($ranges, static fn (array $a, array $b): int => strcmp($a['open'], $b['open']));

        return $ranges;
    }

    /**
     * @return list<array{date: string, label: string}>
     */
    private static function normalizeClosures(mixed $closures): array
    {
        if (!is_array($closures)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($closures as $closure) {
            if (!is_array($closure)) {
                continue;
            }

            $date = trim((string) ($closure['date'] ?? ''));

            if (!self::isDate($date) || isset($seen[$date])) {
                continue;
            }

            $seen[$date] = true;
            $normalized[] = [
                'date' => $date,
                'label' => trim((string) ($closure['label'] ?? '')),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $normalized;
    }

    /**
     * @return array{0: int, 1: int} the range as minutes past local midnight
     */
    private static function rangeMinutes(array $range): array
    {
        return [self::minutes((string) $range['open']), self::minutes((string) $range['close'])];
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hour * 60) + (int) $minute;
    }

    /** A browser time input may send "9:05"; store "09:05". */
    private static function padTime(string $time): string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
            return $time;
        }

        return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
    }

    private static function isTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    private static function isDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
