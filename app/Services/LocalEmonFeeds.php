<?php
	namespace App\Services;

	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Http;
	use UnexpectedValueException;

	class LocalEmonFeeds
	{
		public function latest(): array
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

			return collect($feeds)->filter(fn ($feed) => is_array($feed) && isset($feed['id'], $feed['name']))->map(function($feed)
			{
				$timestamp = is_numeric($feed['time'] ?? null) ? (int) $feed['time'] : null;
				$age = $timestamp === null ? null : CarbonImmutable::now()->getTimestamp() - $timestamp;
				$room = in_array($feed['name'], ['Rad_temperature', 'UFH_temperature']);
				$limit = $room ? ControlSettings::get('nibe.roomTemperatureLookbackMinutes') * 60 : 300;

				return [
					'id'        => (int) $feed['id'], 'name' => (string) $feed['name'],
					'value'     => is_numeric($feed['value'] ?? null) ? (float) $feed['value'] : null,
					'unit'      => is_string($feed['unit'] ?? null) ? $feed['unit'] : '',
					'timestamp' => $timestamp, 'ageSeconds' => $age,
					'stale'     => $age === null || $age < 0 || $age > $limit,
				];
			})->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
		}
	}
