<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Member;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Translation\PotentiallyTranslatedString;

class NoOverlappingTimeEntriesRule implements ValidationRule
{
    public function __construct(
        private readonly Member $member,
        private readonly ?string $excludeTimeEntryId = null
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Get the start and end values from the request
        $requestData = request()->all();
        $start = $requestData['start'] ?? null;
        $end = $requestData['end'] ?? null;

        if (!$start) {
            return; // If no start time, let other validation rules handle it
        }

        try {
            $startDateTime = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $start);
            $endDateTime = $end ? Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $end) : null;
        } catch (\Exception $e) {
            return; // If invalid date format, let other validation rules handle it
        }

        // Build query to find overlapping time entries
        $query = TimeEntry::query()
            ->whereBelongsTo($this->member, 'member')
            ->where(function (Builder $builder) use ($startDateTime, $endDateTime): void {
                if ($endDateTime) {
                    // For time entries with both start and end times, check for any overlap
                    $builder->where(function (Builder $subBuilder) use ($startDateTime, $endDateTime): void {
                        // Case 1: Existing entry starts before our entry ends AND
                        //         (existing entry ends after our entry starts OR existing entry is active)
                        $subBuilder->where('start', '<', $endDateTime)
                            ->where(function (Builder $endBuilder) use ($startDateTime): void {
                                $endBuilder->where('end', '>', $startDateTime)
                                    ->orWhereNull('end'); // Active entries (no end time)
                            });
                    });
                } else {
                    // For active time entries (no end time), let the controller handle the active entry check
                    // We only check for overlaps with completed entries that would conflict
                    $builder->where(function (Builder $subBuilder) use ($startDateTime): void {
                        // Only check completed entries that start before our start and end after our start
                        $subBuilder->whereNotNull('end')
                            ->where('start', '<=', $startDateTime)
                            ->where('end', '>', $startDateTime);
                    });
                }
            });

        // Exclude the current time entry if updating
        if ($this->excludeTimeEntryId) {
            $query->where('id', '!=', $this->excludeTimeEntryId);
        }

        $overlappingEntry = $query->first();

        if ($overlappingEntry) {
            $fail(__('validation.time_entry_overlaps'));
        }
    }
}