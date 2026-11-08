<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_a_booking_with_the_authenticated_user_and_utc_times(): void
    {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            ...$this->validPayload($room),
            'user_id' => $otherUser->id,
        ])
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_accepts_the_same_office_time_with_a_different_iso8601_offset(): void
    {
        $office = Office::factory()->novosibirsk()->create();
        $room = Room::factory()->for($office)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T02:00:00Z',
            'ends_at' => '2026-11-02T03:00:00Z',
        ]))
            ->assertCreated();
    }

    public function test_rejects_a_booking_outside_the_office_working_hours(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T05:45:00Z',
            'ends_at' => '2026-11-02T06:45:00Z',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T16:15:00Z',
            'ends_at' => '2026-11-02T17:15:00Z',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_accepts_minimum_and_maximum_duration_and_rejects_invalid_duration_or_step(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T09:15:00+03:00',
        ]))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2026-11-02T09:15:00+03:00',
            'ends_at' => '2026-11-02T13:15:00+03:00',
        ]))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2026-11-02T19:45:00+03:00',
            'ends_at' => '2026-11-02T20:00:00+03:00',
        ]))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T09:14:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T13:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2026-11-02T09:07:00+03:00',
            'ends_at' => '2026-11-02T10:07:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);
    }

    public function test_rejects_invalid_date_order_and_missing_iso8601_offset(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T10:15:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T10:00:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_invalid_calendar_dates_and_nonzero_seconds(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-02-30T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T10:00:30+03:00',
            'ends_at' => '2026-11-02T11:00:30+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_rejects_overlaps_but_allows_adjacent_bookings_and_another_room(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        $anotherRoom = Room::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->validPayload($room))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T10:45:00+03:00',
            'ends_at' => '2026-11-02T11:45:00+03:00',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'Выбранное время уже занято.');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ]))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload($anotherRoom))
            ->assertCreated();
    }

    public function test_allows_past_dates_because_no_date_restriction_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload(Room::factory()->create(), [
            'starts_at' => '2020-01-02T10:00:00+03:00',
            'ends_at' => '2020-01-02T11:00:00+03:00',
        ]))
            ->assertCreated();
    }

    public function test_only_the_booking_owner_can_cancel_it(): void
    {
        $owner = User::factory()->create();
        $anotherUser = User::factory()->create();
        $booking = Booking::factory()->for($owner)->create();
        Sanctum::actingAs($anotherUser);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_requires_an_existing_room(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', $this->validPayload($room, ['room_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    private function validPayload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $overrides);
    }
}
