<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Tests\Unit\Endpoint\Api\V1\ApiEndpointTestAbstract;
use Laravel\Passport\Passport;

class TimeEntryOverlapTest extends ApiEndpointTestAbstract
{
    public function test_store_endpoint_fails_when_time_entries_overlap(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
        ]);

        // Create an existing time entry
        $existingStart = Carbon::now();
        $existingEnd = Carbon::now()->addHours(2);
        
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->create([
                'start' => $existingStart,
                'end' => $existingEnd,
            ]);

        Passport::actingAs($data->user);

        // Try to create overlapping entry
        $newStart = $existingStart->copy()->addHour();
        $newEnd = $existingEnd->copy()->addHour();

        // Act
        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'description' => 'Test entry',
            'billable' => true,
            'start' => $newStart->format('Y-m-d\TH:i:s\Z'),
            'end' => $newEnd->format('Y-m-d\TH:i:s\Z'),
            'member_id' => $data->member->getKey(),
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start']);
    }

    public function test_store_endpoint_fails_for_active_entry_overlap_with_completed_entry(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
        ]);

        // Create an existing completed time entry
        $existingStart = Carbon::now();
        $existingEnd = Carbon::now()->addHour();
        
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->create([
                'start' => $existingStart,
                'end' => $existingEnd,
            ]);

        Passport::actingAs($data->user);

        // Try to create active entry that would overlap
        $newStart = $existingStart->copy()->addMinutes(30);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'description' => 'Test entry',
            'billable' => true,
            'start' => $newStart->format('Y-m-d\TH:i:s\Z'),
            'end' => null, // Active entry
            'member_id' => $data->member->getKey(),
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start']);
    }

    public function test_store_endpoint_succeeds_when_time_entries_do_not_overlap(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
        ]);

        // Create an existing time entry
        $existingStart = Carbon::now();
        $existingEnd = Carbon::now()->addHour();
        
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->create([
                'start' => $existingStart,
                'end' => $existingEnd,
            ]);

        Passport::actingAs($data->user);

        // Try to create non-overlapping entry (after existing entry ends)
        $newStart = $existingEnd->copy()->addMinute();
        $newEnd = $newStart->copy()->addHour();

        // Act
        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'description' => 'Test entry',
            'billable' => true,
            'start' => $newStart->format('Y-m-d\TH:i:s\Z'),
            'end' => $newEnd->format('Y-m-d\TH:i:s\Z'),
            'member_id' => $data->member->getKey(),
        ]);

        // Assert
        $response->assertStatus(201);
    }

    public function test_update_endpoint_fails_when_updated_time_entry_overlaps_with_existing(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:own',
        ]);

        // Create two existing time entries
        $firstEntry = TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->create([
                'start' => Carbon::now(),
                'end' => Carbon::now()->addHour(),
            ]);

        $secondEntry = TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->create([
                'start' => Carbon::now()->addHours(3),
                'end' => Carbon::now()->addHours(4),
            ]);

        Passport::actingAs($data->user);

        // Try to update second entry to overlap with first entry
        $overlappingStart = $firstEntry->start->copy()->addMinutes(30);

        // Act
        $response = $this->putJson(route('api.v1.time-entries.update', [
            $data->organization->getKey(),
            $secondEntry->getKey()
        ]), [
            'start' => $overlappingStart->format('Y-m-d\TH:i:s\Z'),
        ]);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start']);
    }

    public function test_update_endpoint_succeeds_when_updating_same_entry_without_creating_overlap(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:update:own',
        ]);

        $timeEntry = TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->create([
                'start' => Carbon::now(),
                'end' => Carbon::now()->addHour(),
            ]);

        Passport::actingAs($data->user);

        // Try to update the same entry (should not conflict with itself)
        $newStart = $timeEntry->start->copy()->addMinutes(15);

        // Act
        $response = $this->putJson(route('api.v1.time-entries.update', [
            $data->organization->getKey(),
            $timeEntry->getKey()
        ]), [
            'start' => $newStart->format('Y-m-d\TH:i:s\Z'),
        ]);

        // Assert
        $response->assertSuccessful();
    }
}