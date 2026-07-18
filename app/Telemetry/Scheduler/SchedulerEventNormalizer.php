<?php

declare(strict_types=1);

namespace App\Telemetry\Scheduler;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Produces a stable, bounded event name + type for telemetry from an
 * Illuminate\Console\Scheduling\Event/CallbackEvent instance — never the
 * full shell command, raw arguments/options, closure source, or a dynamic
 * ID (backend-generic-scheduler-instrumentation.md §"Event normalization").
 *
 * Normalization order (Part 4), most-preferred first:
 * 1. Event::$description, when set and non-empty — the developer-authored,
 *    static label from ->name()/->description() (or the job name Schedule::
 *    job() sets automatically — see SchedulerEventType's docblock for why
 *    this can't be told apart from a manually-named closure).
 * 2. The Artisan command name only, extracted from Event::$command via a
 *    bounded regex — never the full built shell command (php binary path,
 *    redirects, `sudo -u`, arguments).
 * 3. A safe, deterministic executable basename for a non-Artisan shell
 *    command (Schedule::exec()) — only when it can be extracted without
 *    parsing/executing shell syntax; otherwise a bare `shell` fallback.
 * 4. A stable event-type fallback (`closure`, `callback`, `unknown`).
 *
 * Descriptions are assumed to be static strings authored at schedule-
 * definition time (e.g. ->name('cleanup-expired-sessions')), exactly like
 * a route name or Artisan command name — this class cannot detect a
 * description built from per-run dynamic data (e.g. interpolating a user
 * ID); schedule authors remain responsible for keeping ->name()/
 * ->description() static, the same assumption HttpRouteNormalizer and
 * ConsoleCommandNormalizer already make about route/command names.
 */
final class SchedulerEventNormalizer
{
    /** Bounded fallback when no safe identifier can be produced at all. */
    public const UNKNOWN = 'unknown';

    /** Maximum length of any identifier folded into an event name. */
    private const MAX_IDENTIFIER_LENGTH = 64;

    public function type(Event $task): SchedulerEventType
    {
        if ($task instanceof CallbackEvent) {
            return $this->hasDescription($task) ? SchedulerEventType::Callback : SchedulerEventType::Closure;
        }

        return $this->isArtisanCommand($task) ? SchedulerEventType::Command : SchedulerEventType::Shell;
    }

    public function name(Event $task, SchedulerEventType $type): string
    {
        try {
            if ($this->hasDescription($task)) {
                return $this->identifierFor($type).':'.$this->sanitize((string) $task->description);
            }

            return match ($type) {
                SchedulerEventType::Command => $this->commandName($task),
                SchedulerEventType::Shell => $this->shellName($task),
                SchedulerEventType::Closure => SchedulerEventType::Closure->value,
                SchedulerEventType::Callback => SchedulerEventType::Callback->value,
                SchedulerEventType::Unknown => self::UNKNOWN,
            };
        } catch (Throwable) {
            return self::UNKNOWN;
        }
    }

    private function hasDescription(Event $task): bool
    {
        return is_string($task->description) && trim($task->description) !== '';
    }

    private function isArtisanCommand(Event $task): bool
    {
        return is_string($task->command) && $this->extractArtisanCommandName($task->command) !== null;
    }

    private function commandName(Event $task): string
    {
        $name = is_string($task->command) ? $this->extractArtisanCommandName($task->command) : null;

        return $name === null
            ? SchedulerEventType::Command->value
            : SchedulerEventType::Command->value.':'.$name;
    }

    /**
     * Extracts only the Artisan command signature name (e.g. `foo:bar`)
     * from the fully-built command string Illuminate\Console\Scheduling\
     * Schedule::command()/exec() stores on Event::$command (php binary +
     * artisan binary + signature + raw parameters) — never the arguments
     * that may follow it. Deliberately conservative: returns null (never
     * a partial/garbled guess) unless the string matches the exact shape
     * Illuminate\Console\Application::formatCommandString() produces.
     */
    private function extractArtisanCommandName(string $command): ?string
    {
        if (! preg_match('/\bartisan[\'"]?\s+([A-Za-z0-9_][A-Za-z0-9_:-]*)/', $command, $matches)) {
            return null;
        }

        return $this->sanitize($matches[1]);
    }

    /**
     * Best-effort, safe executable identifier for a raw shell event
     * (Schedule::exec() with no ->name()) — the first whitespace-delimited
     * token's basename, only when it matches a conservative safe charset.
     * Never the full command line, arguments, or redirection targets
     * (Part 4). Falls back to the bare `shell` type when this cannot be
     * done deterministically and safely.
     */
    private function shellName(Event $task): string
    {
        $command = is_string($task->command) ? trim($task->command) : '';

        if ($command === '') {
            return SchedulerEventType::Shell->value;
        }

        $firstToken = preg_split('/\s+/', $command, 2)[0] ?? '';
        $firstToken = trim($firstToken, "'\"");
        $basename = basename($firstToken);

        if ($basename === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $basename)) {
            return SchedulerEventType::Shell->value;
        }

        return SchedulerEventType::Shell->value.':'.$this->sanitize($basename);
    }

    private function identifierFor(SchedulerEventType $type): string
    {
        return match ($type) {
            SchedulerEventType::Command => SchedulerEventType::Command->value,
            SchedulerEventType::Shell => SchedulerEventType::Shell->value,
            SchedulerEventType::Closure => SchedulerEventType::Closure->value,
            SchedulerEventType::Callback => SchedulerEventType::Callback->value,
            SchedulerEventType::Unknown => self::UNKNOWN,
        };
    }

    /**
     * Bounds any identifier before it becomes part of a metric label:
     * collapses whitespace/control characters and truncates length. Does
     * not (and cannot) guarantee a developer-authored description contains
     * no incidental PII — see class docblock.
     */
    private function sanitize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === '') {
            return self::UNKNOWN;
        }

        return mb_strlen($value) > self::MAX_IDENTIFIER_LENGTH
            ? mb_substr($value, 0, self::MAX_IDENTIFIER_LENGTH)
            : $value;
    }
}
