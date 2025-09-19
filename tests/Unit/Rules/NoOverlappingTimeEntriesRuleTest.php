<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Models\Member;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Rules\NoOverlappingTimeEntriesRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCaseWithDatabase;

class NoOverlappingTimeEntriesRuleTest extends TestCaseWithDatabase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create();
        $this->member = Member::factory()
            ->forOrganization($this->organization)
            ->forUser($this->user)
            ->create();
    }

    public function test_rule_passes_when_no_existing_time_entries(): void
    {
        // Arrange
        $rule = new NoOverlappingTimeEntriesRule($this->member);
        $start = Carbon::now()->format('Y-m-d\TH:i:s\Z');
        $end = Carbon::now()->addHour()->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $start,
            'end' => $end,
        ]);

        $failedValidation = false;
        $failMessage = '';

        // Act
        $rule->validate('start', $start, function (string $message) use (&$failedValidation, &$failMessage): void {
            $failedValidation = true;
            $failMessage = $message;
        });

        // Assert
        $this->assertFalse($failedValidation, "Validation should pass when no existing time entries exist. Failed with: {$failMessage}");
    }

    public function test_rule_fails_when_time_entries_overlap(): void
    {
        // Arrange
        $existingStart = Carbon::now();
        $existingEnd = Carbon::now()->addHours(2);

        TimeEntry::factory()
            ->forOrganization($this->organization)
            ->forMember($this->member)
            ->create([
                'start' => $existingStart,
                'end' => $existingEnd,
            ]);

        $rule = new NoOverlappingTimeEntriesRule($this->member);

        // Test overlapping entry (starts during existing entry)
        $newStart = $existingStart->copy()->addHour()->format('Y-m-d\TH:i:s\Z');
        $newEnd = $existingEnd->copy()->addHour()->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $newStart,
            'end' => $newEnd,
        ]);

        $failedValidation = false;

        // Act
        $rule->validate('start', $newStart, function (string $message) use (&$failedValidation): void {
            $failedValidation = true;
        });

        // Assert
        $this->assertTrue($failedValidation, 'Validation should fail when time entries overlap');
    }

    public function test_rule_passes_when_time_entries_do_not_overlap(): void
    {
        // Arrange
        $existingStart = Carbon::now();
        $existingEnd = Carbon::now()->addHour();

        TimeEntry::factory()
            ->forOrganization($this->organization)
            ->forMember($this->member)
            ->create([
                'start' => $existingStart,
                'end' => $existingEnd,
            ]);

        $rule = new NoOverlappingTimeEntriesRule($this->member);

        // Test non-overlapping entry (starts after existing entry ends)
        $newStart = $existingEnd->copy()->addMinute()->format('Y-m-d\TH:i:s\Z');
        $newEnd = $existingEnd->copy()->addHours(2)->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $newStart,
            'end' => $newEnd,
        ]);

        $failedValidation = false;

        // Act
        $rule->validate('start', $newStart, function (string $message) use (&$failedValidation): void {
            $failedValidation = true;
        });

        // Assert
        $this->assertFalse($failedValidation, 'Validation should pass when time entries do not overlap');
    }

    public function test_rule_allows_active_entries_to_be_handled_by_controller(): void
    {
        // Arrange
        TimeEntry::factory()
            ->forOrganization($this->organization)
            ->forMember($this->member)
            ->active() // No end time
            ->create([
                'start' => Carbon::now()->subHour(),
            ]);

        $rule = new NoOverlappingTimeEntriesRule($this->member);

        $newStart = Carbon::now()->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $newStart,
            'end' => null,
        ]);

        $failedValidation = false;

        // Act
        $rule->validate('start', $newStart, function (string $message) use (&$failedValidation): void {
            $failedValidation = true;
        });

        // Assert
        $this->assertFalse($failedValidation, 'Validation should pass for active entries - let controller handle them');
    }

    public function test_rule_blocks_active_entry_that_would_overlap_completed_entry(): void
    {
        // Arrange
        $existingStart = Carbon::now();
        $existingEnd = Carbon::now()->addHour();
        
        TimeEntry::factory()
            ->forOrganization($this->organization)
            ->forMember($this->member)
            ->create([
                'start' => $existingStart,
                'end' => $existingEnd,
            ]);

        $rule = new NoOverlappingTimeEntriesRule($this->member);

        // Try to start active entry that would overlap with the completed entry
        $newStart = $existingStart->copy()->addMinutes(30)->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $newStart,
            'end' => null,
        ]);

        $failedValidation = false;

        // Act
        $rule->validate('start', $newStart, function (string $message) use (&$failedValidation): void {
            $failedValidation = true;
        });

        // Assert
        $this->assertTrue($failedValidation, 'Validation should fail when active entry would overlap with completed entry');
    }

    public function test_rule_excludes_current_time_entry_when_updating(): void
    {
        // Arrange
        $existingTimeEntry = TimeEntry::factory()
            ->forOrganization($this->organization)
            ->forMember($this->member)
            ->create([
                'start' => Carbon::now(),
                'end' => Carbon::now()->addHour(),
            ]);

        $rule = new NoOverlappingTimeEntriesRule($this->member, $existingTimeEntry->id);

        // Test updating the same time entry (should not conflict with itself)
        $newStart = $existingTimeEntry->start->format('Y-m-d\TH:i:s\Z');
        $newEnd = $existingTimeEntry->end->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $newStart,
            'end' => $newEnd,
        ]);

        $failedValidation = false;

        // Act
        $rule->validate('start', $newStart, function (string $message) use (&$failedValidation): void {
            $failedValidation = true;
        });

        // Assert
        $this->assertFalse($failedValidation, 'Validation should pass when updating the same time entry');
    }

    public function test_rule_detects_overlap_with_different_member(): void
    {
        // Arrange
        $otherUser = User::factory()->create();
        $otherMember = Member::factory()
            ->forOrganization($this->organization)
            ->forUser($otherUser)
            ->create();

        // Create time entry for other member
        TimeEntry::factory()
            ->forOrganization($this->organization)
            ->forMember($otherMember)
            ->create([
                'start' => Carbon::now(),
                'end' => Carbon::now()->addHour(),
            ]);

        $rule = new NoOverlappingTimeEntriesRule($this->member);

        // Test creating overlapping entry for our member (should be allowed - different users)
        $newStart = Carbon::now()->addMinutes(30)->format('Y-m-d\TH:i:s\Z');
        $newEnd = Carbon::now()->addMinutes(90)->format('Y-m-d\TH:i:s\Z');

        request()->merge([
            'start' => $newStart,
            'end' => $newEnd,
        ]);

        $failedValidation = false;

        // Act
        $rule->validate('start', $newStart, function (string $message) use (&$failedValidation): void {
            $failedValidation = true;
        });

        // Assert
        $this->assertFalse($failedValidation, 'Validation should pass when overlapping with different member\'s time entry');
    }
}