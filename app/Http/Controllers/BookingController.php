<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => [
                'required',
                'string',
                fn (string $attribute, mixed $value, \Closure $fail) => $this->validateIso8601WithOffset($attribute, $value, $fail),
            ],
            'ends_at' => [
                'required',
                'string',
                fn (string $attribute, mixed $value, \Closure $fail) => $this->validateIso8601WithOffset($attribute, $value, $fail),
            ],
        ], [
            'room_id.required' => 'Комната обязательна для бронирования.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.exists' => 'Выбранная комната не существует.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Укажите время начала встречи.',
            'starts_at.string' => 'Время начала должно быть строкой ISO 8601.',
            'ends_at.required' => 'Укажите время окончания встречи.',
            'ends_at.string' => 'Время окончания должно быть строкой ISO 8601.',
        ]);

        $startsAt = Carbon::parse($validated['starts_at'])->utc();
        $endsAt = Carbon::parse($validated['ends_at'])->utc();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $booking = DB::transaction(function () use ($validated, $startsAt, $endsAt, $user): ?Booking {
            $room = Room::query()
                ->with('office')
                ->lockForUpdate()
                ->findOrFail($validated['room_id']);

            $this->validateSchedule($startsAt, $endsAt, $room->office->timezone);

            $hasConflict = Booking::query()
                ->where('room_id', $room->id)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($hasConflict) {
                return null;
            }

            $booking = new Booking([
                'room_id' => $room->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'title' => $validated['title'],
            ]);
            $booking->user()->associate($user);
            $booking->save();

            return $booking;
        });

        if ($booking === null) {
            return response()->json(['error' => 'Выбранное время уже занято.'], 409);
        }

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $request->user()->bookings()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }

    private function validateIso8601WithOffset(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            $fail('Укажите дату и время в формате ISO 8601 со смещением часового пояса.');

            return;
        }

        try {
            new DateTimeImmutable($value);
        } catch (\Exception) {
            $fail('Укажите корректную дату и время.');

            return;
        }

        if (DateTimeImmutable::getLastErrors() !== false) {
            $fail('Укажите корректную дату и время.');
        }
    }

    private function validateSchedule(Carbon $startsAt, Carbon $endsAt, string $timezone): void
    {
        $errors = [];

        if ($endsAt <= $startsAt) {
            $errors['ends_at'][] = 'Время окончания должно быть позже времени начала.';
        }

        $durationInSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($durationInSeconds < 15 * 60 || $durationInSeconds > 4 * 60 * 60 || $durationInSeconds % (15 * 60) !== 0) {
            $errors['ends_at'][] = 'Длительность встречи должна быть от 15 минут до 4 часов с шагом 15 минут.';
        }

        $localStartsAt = $startsAt->copy()->setTimezone($timezone);
        $localEndsAt = $endsAt->copy()->setTimezone($timezone);
        $startMinute = ((int) $localStartsAt->format('H')) * 60 + (int) $localStartsAt->format('i');
        $endMinute = ((int) $localEndsAt->format('H')) * 60 + (int) $localEndsAt->format('i');

        if (
            $localStartsAt->format('Y-m-d') !== $localEndsAt->format('Y-m-d')
            || $startMinute < 9 * 60
            || $endMinute > 20 * 60
        ) {
            $errors['starts_at'][] = 'Вся встреча должна проходить в рабочее время офиса: с 09:00 до 20:00.';
        }

        foreach ([
            'starts_at' => $localStartsAt,
            'ends_at' => $localEndsAt,
        ] as $attribute => $localDateTime) {
            if (
                (int) $localDateTime->format('i') % 15 !== 0
                || $localDateTime->format('s') !== '00'
                || $localDateTime->format('u') !== '000000'
            ) {
                $errors[$attribute][] = 'Начало и окончание встречи должны приходиться на шаг 15 минут.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
