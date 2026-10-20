<?php
	namespace App\Services;

	use App\Models\EmonFeedMap;
	use App\Models\NibeParameter;
	use App\Models\NibeToEmonFeedMap;
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

			$feeds = collect($feeds)->filter(fn ($feed) => is_array($feed) && is_numeric($feed['id'] ?? null) && is_string($feed['name'] ?? null));
			$feedMappings = EmonFeedMap::pluck('localName', 'localFeedId')->all();
			// Room aliases belong to this app's mappings; Emon's display names can differ or repeat.
			$roomFeeds = array_intersect($feedMappings, ['Rad_temperature', 'UFH_temperature']);
			$mappedRooms = array_flip($roomFeeds);
			$nibeMappings = NibeToEmonFeedMap::get(['localFeedId', 'localName', 'title']);
			$nibeFeedIds = $nibeMappings->pluck('localFeedId')->filter(fn ($id) => $id !== null)->mapWithKeys(fn ($id) => [(int)$id => true])->all();
			$nibeNames = NibeParameter::pluck('title')->merge($nibeMappings->pluck('title'))->merge($nibeMappings->pluck('localName'))
				->filter(fn ($name) => is_string($name) && trim($name) !== '')->mapWithKeys(fn ($name) => [mb_strtolower(trim($name)) => true])->all();
			$roomDevices = [];

			// A mapped room sensor also identifies sibling feeds sharing its device tag.
			foreach ($feeds as $feed)
			{
				$alias = $feedMappings[(int)$feed['id']] ?? '';
				$device = $this->mappedDevice($alias);
				$tag = is_string($feed['tag'] ?? null) ? trim($feed['tag']) : '';

				if ($tag !== '' && (str_starts_with($alias, 'Rad_') || str_starts_with($alias, 'UFH_')))
				{
					// A tag shared by both zones cannot identify the parent of an unmapped sibling.
					$roomDevices[$tag] = array_key_exists($tag, $roomDevices) && $roomDevices[$tag] !== $device ? null : $device;
				}
			}

			return $feeds->reject(function($feed) use ($feedMappings, $nibeFeedIds, $nibeNames)
			{
				$id = (int)$feed['id'];
				$name = mb_strtolower(trim($feed['name']));
				$tag = is_string($feed['tag'] ?? null) ? trim($feed['tag']) : '';

				// Prefer source metadata; a similarly named reading on another device must remain visible.
				return isset($nibeFeedIds[$id]) || preg_match('/^nibe(?:$|[ _-])/i', $tag) === 1 || preg_match('/^nibe[ _-]/i', $name) === 1
					|| ($tag === '' && !isset($feedMappings[$id]) && isset($nibeNames[$name]));
			})->map(function($feed) use ($feedMappings, $roomFeeds, $mappedRooms, $roomDevices)
			{
				$timestamp = is_numeric($feed['time'] ?? null) ? (int) $feed['time'] : null;
				$age = $timestamp === null ? null : CarbonImmutable::now()->getTimestamp() - $timestamp;
				$roomName = $roomFeeds[(int)$feed['id']] ?? (isset($mappedRooms[$feed['name']]) ? null : $feed['name']);
				$room = ['Rad_temperature' => 'rad', 'UFH_temperature' => 'ufh'][$roomName] ?? null;
				$limit = $room !== null ? ControlSettings::get('nibe.roomTemperatureLookbackMinutes') * 60 : 300;
				$alias = $feedMappings[(int)$feed['id']] ?? '';
				$tag = is_string($feed['tag'] ?? null) ? trim($feed['tag']) : '';
				$mappedDevice = $this->mappedDevice($alias);
				$roomDevice = str_starts_with($alias, 'Rad_') || str_starts_with($alias, 'UFH_') ? $mappedDevice : null;
				$device = $roomDevice ?? ($tag !== '' ? ($roomDevices[$tag] ?? $tag) : ($mappedDevice ?? 'Other feeds'));

				if ($room !== null && $device === 'Other feeds')
				{
					$device = $room === 'rad' ? 'Radiator zone · Main building' : 'UFH zone · Annexe';
				}

				return [
					'id'        => (int) $feed['id'], 'name' => (string) $feed['name'],
					'device'    => $device,
					'room'      => $room,
					'value'     => is_numeric($feed['value'] ?? null) ? (float) $feed['value'] : null,
					'unit'      => is_string($feed['unit'] ?? null) ? $feed['unit'] : '',
					'timestamp' => $timestamp, 'ageSeconds' => $age,
					'stale'     => $age === null || $age < 0 || $age > $limit,
				];
			})->sort(fn ($left, $right) => strnatcasecmp($left['device'], $right['device']) ?: strcmp($left['device'], $right['device']) ?: strnatcasecmp($left['name'], $right['name']) ?: $left['id'] <=> $right['id'])->values()->all();
		}

		private function mappedDevice(string $alias) : ?string
		{
			$parent = explode('_', $alias, 2)[0];

			return ['Rad' => 'Radiator zone · Main building', 'UFH' => 'UFH zone · Annexe', 'heatmeter' => 'Heat meter', 'total' => 'Electricity meter'][$parent] ?? null;
		}
	}
