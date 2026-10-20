<?php
	namespace App\Services;

	use App\Models\EmonFeedMap;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Http;
	use UnexpectedValueException;

	class LocalEmonFeeds
	{
		public function latest() : array
		{
			$endpoint = config('emon.emonHpLocalEndpoint');
			$key = config('emon.emonHpLocalReadKey');

			if (!$endpoint || !$key)
			{
				throw new UnexpectedValueException('Local Emon is not configured.');
			}

			// This dashboard-only read is bounded; a failed Emon connection must not block the page.
			$feeds = Http::connectTimeout(2)->timeout(4)->get(rtrim($endpoint, '/').'/feed/list.json', ['apikey' => $key])->throw()->json();

			if (!is_array($feeds) || !array_is_list($feeds))
			{
				throw new UnexpectedValueException('Unexpected Emon feed response.');
			}

			// Room aliases belong to this app's mappings; Emon's display names can differ or repeat.
			$roomFeeds = EmonFeedMap::whereIn('localName', ['Rad_temperature', 'UFH_temperature'])->pluck('localName', 'localFeedId')->all();
			$mappedRooms = array_flip($roomFeeds);

			return collect($feeds)->filter(fn ($feed) => is_array($feed) && isset($feed['id'], $feed['name']))->map(function($feed) use ($roomFeeds, $mappedRooms)
			{
				$timestamp = is_numeric($feed['time'] ?? null) ? (int) $feed['time'] : null;
				$age = $timestamp === null ? null : CarbonImmutable::now()->getTimestamp() - $timestamp;
				$roomName = $roomFeeds[(int)$feed['id']] ?? (isset($mappedRooms[$feed['name']]) ? null : $feed['name']);
				$room = ['Rad_temperature' => 'rad', 'UFH_temperature' => 'ufh'][$roomName] ?? null;
				$limit = $room !== null ? ControlSettings::get('nibe.roomTemperatureLookbackMinutes') * 60 : 300;

				return [
					'id'        => (int) $feed['id'], 'name' => (string) $feed['name'],
					'room'      => $room,
					'value'     => is_numeric($feed['value'] ?? null) ? (float) $feed['value'] : null,
					'unit'      => is_string($feed['unit'] ?? null) ? $feed['unit'] : '',
					'timestamp' => $timestamp, 'ageSeconds' => $age,
					'stale'     => $age === null || $age < 0 || $age > $limit,
				];
			})->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
		}
	}
