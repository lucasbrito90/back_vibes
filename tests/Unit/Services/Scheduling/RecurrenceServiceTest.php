<?php

declare(strict_types=1);

use App\Services\Scheduling\Exceptions\InvalidRecurrenceConfigurationException;
use App\Services\Scheduling\Exceptions\UnsupportedRecurrenceTypeException;
use App\Services\Scheduling\RecurrenceService;
use App\Services\Scheduling\RecurrenceType;
use App\Services\Scheduling\ScheduleInput;
use Carbon\CarbonImmutable;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a ScheduleInput anchored to a given UTC CarbonImmutable.
 */
function makeInput(
    CarbonImmutable $startTimeUtc,
    RecurrenceType $type,
    string $timezone = 'UTC',
    ?array $recurrenceConfig = null,
    bool $isEnabled = true,
): ScheduleInput {
    return new ScheduleInput(
        timezone: $timezone,
        startTime: $startTimeUtc,
        recurrenceType: $type,
        recurrenceConfig: $recurrenceConfig,
        isEnabled: $isEnabled,
    );
}

// ---------------------------------------------------------------------------
// once — single fire
// ---------------------------------------------------------------------------

test('once: before anchor returns anchor', function () {
    $anchor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-06-15 08:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Once);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-06-15 09:00:00');
});

test('once: exactly at anchor returns null', function () {
    $anchor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Once);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->toBeNull();
});

test('once: after anchor returns null', function () {
    $anchor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-06-15 10:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Once);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// daily
// ---------------------------------------------------------------------------

test('daily: before today\'s local time returns same-day occurrence', function () {
    // anchor is 09:00 UTC on some reference day
    $anchor = CarbonImmutable::parse('2024-01-01 09:00:00', 'UTC');
    // afterUtc is on the same wall-clock day but earlier than 09:00
    $afterUtc = CarbonImmutable::parse('2024-06-15 08:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'UTC');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-06-15 09:00:00');
});

test('daily: after today\'s local time returns next-day occurrence', function () {
    $anchor = CarbonImmutable::parse('2024-01-01 09:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-06-15 10:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'UTC');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-06-16 09:00:00');
});

// ---------------------------------------------------------------------------
// weekdays — Mon–Fri only
// ---------------------------------------------------------------------------

test('weekdays: Friday after time returns next Monday', function () {
    // anchor at 14:00 UTC; afterUtc is Friday 15:00 (after today's slot)
    // 2024-01-19 is a Friday
    $anchor = CarbonImmutable::parse('2024-01-01 14:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-19 15:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekdays, 'UTC');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-01-22 is Monday
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-01-22 14:00:00');
});

test('weekdays: Saturday returns next Monday', function () {
    // 2024-01-20 is a Saturday
    $anchor = CarbonImmutable::parse('2024-01-01 14:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-20 08:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekdays, 'UTC');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-01-22 is Monday
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-01-22 14:00:00');
});

// ---------------------------------------------------------------------------
// weekly — ISO weekdays via recurrence_config.days_of_week
// ---------------------------------------------------------------------------

test('weekly: single day returns next matching weekday', function () {
    // days_of_week = [3] = Wednesday; anchor time 14:00 UTC
    // afterUtc is Monday 2024-01-15
    $anchor = CarbonImmutable::parse('2024-01-01 14:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => [3]]);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-01-17 is Wednesday
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-01-17 14:00:00');
});

test('weekly: multiple days returns closest upcoming day', function () {
    // days_of_week = [2, 4] = Tuesday, Thursday; anchor time 10:00 UTC
    // afterUtc is Wednesday 2024-01-17 09:00
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-17 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => [2, 4]]);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-01-18 is Thursday
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-01-18 10:00:00');
});

test('weekly: week boundary wraps to next week', function () {
    // days_of_week = [1] = Monday; anchor 10:00 UTC
    // afterUtc is Monday 2024-01-15 after the slot
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 11:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => [1]]);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // Next Monday is 2024-01-22
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-01-22 10:00:00');
});

// ---------------------------------------------------------------------------
// Timezone tests
// ---------------------------------------------------------------------------

test('timezone UTC: daily returns UTC occurrence', function () {
    $anchor = CarbonImmutable::parse('2024-03-01 08:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-03-10 07:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'UTC');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-03-10 08:00:00');
});

test('timezone America/Sao_Paulo: daily returns occurrence in BRT offset', function () {
    // Sao Paulo is UTC-3 (no DST post-2019).
    // Anchor: 2024-01-01 12:00:00 BRT = 2024-01-01 15:00:00 UTC
    $anchor = CarbonImmutable::parse('2024-01-01 15:00:00', 'UTC');
    // afterUtc: 2024-06-10 14:00:00 UTC = 2024-06-10 11:00 BRT (before local 12:00)
    $afterUtc = CarbonImmutable::parse('2024-06-10 14:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'America/Sao_Paulo');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // Same day in local tz: 2024-06-10 12:00 BRT = 2024-06-10 15:00 UTC
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-06-10 15:00:00');
});

test('timezone Europe/London: daily returns occurrence in GMT offset', function () {
    // London in winter = UTC+0, in summer = UTC+1 (BST)
    // Anchor: 2024-01-15 09:00 GMT = 09:00 UTC
    $anchor = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');
    // afterUtc: 2024-02-10 08:00:00 UTC = 08:00 GMT (before 09:00 local)
    $afterUtc = CarbonImmutable::parse('2024-02-10 08:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'Europe/London');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-02-10 09:00 GMT = 09:00 UTC
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-02-10 09:00:00');
});

// ---------------------------------------------------------------------------
// DST — spring-forward (skip nonexistent local time)
// ---------------------------------------------------------------------------

test('DST spring-forward: nonexistent local time is skipped', function () {
    // Europe/London 2024-03-31: clocks go 01:00 GMT → 02:00 BST at 01:00 UTC.
    // Local 01:30 does not exist on that day.
    // Anchor (set in winter): 2024-01-15 01:30 GMT = 01:30 UTC
    $anchor = CarbonImmutable::parse('2024-01-15 01:30:00', 'UTC');

    // afterUtc: day before spring-forward, at 14:00 UTC
    $afterUtc = CarbonImmutable::parse('2024-03-30 14:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'Europe/London');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-03-31 01:30 does not exist — SKIPPED.
    // 2024-04-01 01:30 BST = 00:30 UTC
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-04-01 00:30:00');
});

// ---------------------------------------------------------------------------
// DST — fall-back (first/earlier UTC occurrence wins)
// ---------------------------------------------------------------------------

test('DST fall-back: ambiguous local time uses first (earlier) UTC occurrence', function () {
    // Europe/London 2024-10-27: clocks go 02:00 BST → 01:00 GMT at 01:00 UTC.
    // Local 01:30 occurs twice: first at 00:30 UTC (BST), then at 01:30 UTC (GMT).
    // "First occurrence wins" → 00:30 UTC.
    //
    // Anchor (set in summer): 2024-07-01 01:30 BST = 00:30 UTC
    $anchor = CarbonImmutable::parse('2024-07-01 00:30:00', 'UTC');

    // afterUtc: just before the fall-back day
    $afterUtc = CarbonImmutable::parse('2024-10-26 23:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'Europe/London');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // First 01:30 on fall-back day = 00:30 UTC
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-10-27 00:30:00');
});

// ---------------------------------------------------------------------------
// Disabled schedule
// ---------------------------------------------------------------------------

test('disabled schedule returns null', function () {
    $anchor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-06-15 08:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'UTC', null, false);
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// Occurrence key
// ---------------------------------------------------------------------------

test('occurrence key is stable for same schedule_id and scheduled_for', function () {
    $scheduledFor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $service = new RecurrenceService;

    $key1 = $service->computeOccurrenceKey(42, $scheduledFor);
    $key2 = $service->computeOccurrenceKey(42, $scheduledFor);

    expect($key1)->toBe($key2);
});

test('occurrence key changes when scheduled_for changes', function () {
    $scheduledFor1 = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $scheduledFor2 = CarbonImmutable::parse('2024-06-16 09:00:00', 'UTC');
    $service = new RecurrenceService;

    $key1 = $service->computeOccurrenceKey(42, $scheduledFor1);
    $key2 = $service->computeOccurrenceKey(42, $scheduledFor2);

    expect($key1)->not->toBe($key2);
});

test('occurrence key format is {schedule_id}:{unix_timestamp}', function () {
    $scheduledFor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $service = new RecurrenceService;

    $key = $service->computeOccurrenceKey(7, $scheduledFor);

    $expectedUnix = $scheduledFor->timestamp;
    expect($key)->toBe("7:{$expectedUnix}");
});

// ---------------------------------------------------------------------------
// monthly — reserved/unsupported (MVP: enum slot only, no recurrence logic)
// ---------------------------------------------------------------------------

test('computeNextRunAt throws UnsupportedRecurrenceTypeException for RecurrenceType::Monthly', function () {
    expect(RecurrenceType::Monthly->isMvpSupported())->toBeFalse();

    $anchor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-06-15 08:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Monthly);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(
            UnsupportedRecurrenceTypeException::class,
            'Recurrence type [monthly] is not supported in the current MVP.'
        );
});

// ---------------------------------------------------------------------------
// weekly — invalid recurrence_config validation
// ---------------------------------------------------------------------------

test('weekly: null config (no days_of_week) throws InvalidRecurrenceConfigurationException', function () {
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    // recurrenceConfig = null — days_of_week is missing entirely
    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', null);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(InvalidRecurrenceConfigurationException::class);
});

test('weekly: empty days_of_week throws InvalidRecurrenceConfigurationException', function () {
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => []]);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(InvalidRecurrenceConfigurationException::class);
});

test('weekly: days_of_week containing 0 throws InvalidRecurrenceConfigurationException', function () {
    // ISO weekday range is 1–7; 0 is out of range
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => [0]]);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(InvalidRecurrenceConfigurationException::class);
});

test('weekly: days_of_week containing 8 throws InvalidRecurrenceConfigurationException', function () {
    // ISO weekday range is 1–7; 8 is out of range
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => [8]]);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(InvalidRecurrenceConfigurationException::class);
});

test('weekly: days_of_week containing a string throws InvalidRecurrenceConfigurationException', function () {
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => ['mon']]);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(InvalidRecurrenceConfigurationException::class);
});

test('weekly: days_of_week value of null throws InvalidRecurrenceConfigurationException', function () {
    $anchor = CarbonImmutable::parse('2024-01-01 10:00:00', 'UTC');
    $afterUtc = CarbonImmutable::parse('2024-01-15 09:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Weekly, 'UTC', ['days_of_week' => null]);
    $service = new RecurrenceService;

    expect(fn () => $service->computeNextRunAt($input, $afterUtc))
        ->toThrow(InvalidRecurrenceConfigurationException::class);
});

// ---------------------------------------------------------------------------
// DST — America/New_York (ET, UTC-5/UTC-4)
// ---------------------------------------------------------------------------

test('DST spring-forward America/New_York: nonexistent local time is skipped', function () {
    // America/New_York 2024-03-10: clocks go 02:00 EST → 03:00 EDT at 07:00 UTC.
    // Local 02:30 does not exist on that day.
    // Anchor (set in winter EST): 2024-01-15 02:30 EST = 07:30 UTC
    $anchor = CarbonImmutable::parse('2024-01-15 07:30:00', 'UTC');

    // afterUtc: day before spring-forward
    $afterUtc = CarbonImmutable::parse('2024-03-09 14:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'America/New_York');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-03-10 02:30 EST does not exist — SKIPPED.
    // 2024-03-11 02:30 EDT (UTC-4) = 06:30 UTC
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-03-11 06:30:00');
});

test('DST fall-back America/New_York: ambiguous local time uses first (earlier) UTC occurrence', function () {
    // America/New_York 2024-11-03: clocks go 02:00 EDT → 01:00 EST at 06:00 UTC.
    // Local 01:30 occurs twice: first at 05:30 UTC (EDT = UTC-4), then at 06:30 UTC (EST = UTC-5).
    // "First occurrence wins" → 05:30 UTC.
    //
    // Anchor (set in summer EDT): 2024-07-01 01:30 EDT = 05:30 UTC
    $anchor = CarbonImmutable::parse('2024-07-01 05:30:00', 'UTC');

    // afterUtc: just before the fall-back day
    $afterUtc = CarbonImmutable::parse('2024-11-02 23:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'America/New_York');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // First 01:30 on fall-back day = 05:30 UTC (pre-transition, EDT)
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-11-03 05:30:00');
});

// ---------------------------------------------------------------------------
// DST — America/Vancouver (PT, UTC-8/UTC-7)
// ---------------------------------------------------------------------------

test('DST spring-forward America/Vancouver: nonexistent local time is skipped', function () {
    // America/Vancouver 2024-03-10: clocks go 02:00 PST → 03:00 PDT at 10:00 UTC.
    // Local 02:30 does not exist on that day.
    // Anchor (set in winter PST): 2024-01-15 02:30 PST = 10:30 UTC
    $anchor = CarbonImmutable::parse('2024-01-15 10:30:00', 'UTC');

    // afterUtc: day before spring-forward
    $afterUtc = CarbonImmutable::parse('2024-03-09 12:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'America/Vancouver');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // 2024-03-10 02:30 PST does not exist — SKIPPED.
    // 2024-03-11 02:30 PDT (UTC-7) = 09:30 UTC
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-03-11 09:30:00');
});

test('DST fall-back America/Vancouver: ambiguous local time uses first (earlier) UTC occurrence', function () {
    // America/Vancouver 2024-11-03: clocks go 02:00 PDT → 01:00 PST at 09:00 UTC.
    // Local 01:30 occurs twice: first at 08:30 UTC (PDT = UTC-7), then at 09:30 UTC (PST = UTC-8).
    // "First occurrence wins" → 08:30 UTC.
    //
    // Anchor (set in summer PDT): 2024-07-01 01:30 PDT = 08:30 UTC
    $anchor = CarbonImmutable::parse('2024-07-01 08:30:00', 'UTC');

    // afterUtc: just before the fall-back day
    $afterUtc = CarbonImmutable::parse('2024-11-02 22:00:00', 'UTC');

    $input = makeInput($anchor, RecurrenceType::Daily, 'America/Vancouver');
    $service = new RecurrenceService;
    $result = $service->computeNextRunAt($input, $afterUtc);

    // First 01:30 on fall-back day = 08:30 UTC (pre-transition, PDT)
    expect($result)->not->toBeNull()
        ->and($result->utc()->toDateTimeString())->toBe('2024-11-03 08:30:00');
});

// ---------------------------------------------------------------------------
// Occurrence key — hardening
// ---------------------------------------------------------------------------

test('occurrence key changes when schedule_id changes', function () {
    $scheduledFor = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $service = new RecurrenceService;

    $key1 = $service->computeOccurrenceKey(42, $scheduledFor);
    $key2 = $service->computeOccurrenceKey(99, $scheduledFor);

    expect($key1)->not->toBe($key2);
});

test('occurrence key is unaffected by timezone of the scheduled_for argument', function () {
    // The same UTC instant expressed in different timezone representations
    // must always produce the same key (key is based on Unix timestamp, not TZ display).
    $scheduledForUtc = CarbonImmutable::parse('2024-06-15 09:00:00', 'UTC');
    $scheduledForSaoPaulo = $scheduledForUtc->setTimezone('America/Sao_Paulo');
    $scheduledForVancouver = $scheduledForUtc->setTimezone('America/Vancouver');
    $service = new RecurrenceService;

    $keyUtc = $service->computeOccurrenceKey(1, $scheduledForUtc);
    $keySaoPaulo = $service->computeOccurrenceKey(1, $scheduledForSaoPaulo);
    $keyVancouver = $service->computeOccurrenceKey(1, $scheduledForVancouver);

    expect($keyUtc)->toBe($keySaoPaulo)
        ->and($keyUtc)->toBe($keyVancouver);
});
