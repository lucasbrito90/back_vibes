<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Requests\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Services\Scheduling\RecurrenceService;
use App\Services\Scheduling\RecurrenceType;
use App\Services\Scheduling\ScheduleInput;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly RecurrenceService $recurrenceService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Schedule::class);

        $schedules = Schedule::where('user_id', $request->user()->id)
            ->with(['vibe' => fn ($q) => $q->withCount('deviceActions')])
            ->orderByDesc('created_at')
            ->get();

        return ScheduleResource::collection($schedules);
    }

    public function store(StoreScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', Schedule::class);

        $validated = $request->validated();

        $isEnabled = (bool) ($validated['is_enabled'] ?? true);

        $input = $this->buildScheduleInput($validated, $isEnabled);
        $nextRunAt = $this->recurrenceService->computeNextRunAt($input);

        $schedule = Schedule::create([
            'user_id' => $request->user()->id,
            'vibe_id' => $validated['vibe_id'],
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'start_time' => $validated['start_time'],
            'recurrence_type' => $validated['recurrence_type'],
            'recurrence_config' => $validated['recurrence_config'] ?? null,
            'is_enabled' => $isEnabled,
            'next_run_at' => $nextRunAt,
        ]);

        $schedule->load(['vibe' => fn ($q) => $q->withCount('deviceActions')]);

        return (new ScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function show(Request $request, Schedule $schedule): ScheduleResource
    {
        $this->authorize('view', $schedule);

        $schedule->load(['vibe' => fn ($q) => $q->withCount('deviceActions')]);

        return new ScheduleResource($schedule);
    }

    public function update(UpdateScheduleRequest $request, Schedule $schedule): ScheduleResource
    {
        $this->authorize('update', $schedule);

        $validated = $request->validated();

        $recurrenceFields = ['vibe_id', 'timezone', 'start_time', 'recurrence_type', 'recurrence_config', 'is_enabled'];
        $recurrenceChanged = ! empty(array_intersect(array_keys($validated), $recurrenceFields));

        $schedule->fill($validated);

        if ($recurrenceChanged) {
            $isEnabled = $schedule->is_enabled;

            $input = $this->buildScheduleInput([
                'timezone' => $schedule->timezone,
                'start_time' => $schedule->start_time->toDateTimeString(),
                'recurrence_type' => $schedule->recurrence_type,
                'recurrence_config' => $schedule->recurrence_config,
            ], $isEnabled);

            $schedule->next_run_at = $this->recurrenceService->computeNextRunAt($input);
        }

        $schedule->save();

        $schedule->load(['vibe' => fn ($q) => $q->withCount('deviceActions')]);

        return new ScheduleResource($schedule);
    }

    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $schedule->delete();

        return response()->json(null, 204);
    }

    private function buildScheduleInput(array $fields, bool $isEnabled): ScheduleInput
    {
        return new ScheduleInput(
            timezone: $fields['timezone'],
            startTime: CarbonImmutable::parse($fields['start_time'], 'UTC'),
            recurrenceType: RecurrenceType::from($fields['recurrence_type']),
            recurrenceConfig: $fields['recurrence_config'] ?? null,
            isEnabled: $isEnabled,
        );
    }
}
