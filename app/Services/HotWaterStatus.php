<?php
	namespace App\Services;

	use Carbon\CarbonImmutable;
	use Throwable;

	class HotWaterStatus
	{
		public static function rows(?string $value) : array
		{
			if ($value === null || trim($value) === '')
			{
				return ['Control state' => 'Not yet recorded.'];
			}

			$state = json_decode($value, true);

			if (!is_array($state) || ($state !== [] && array_is_list($state)))
			{
				return ['Control state' => 'Unavailable: the saved control state could not be read.'];
			}

			$request = $state['request'] ?? null;
			$completed = is_array($state['completed'] ?? null) ? $state['completed'] : [];
			$rows =
			[
				'Latest decision'     => is_string($state['reason'] ?? null) && $state['reason'] !== '' ? $state['reason'] : 'Not yet recorded.',
				'Managed by app'      => match ($state['managed'] ?? null) { true => 'Yes', false => 'No', default => 'Unknown' },
				'Comfort mode'        => self::mode($state['mode'] ?? null),
				'Pending mode change' => self::mode($state['pendingMode'] ?? null, 'None'),
				'Current recharge'    => $request === null ? 'None' : (is_array($request) ? (self::hasTime($request['startedAt'] ?? null) ? 'Production observed' : 'Requested; waiting for production') : 'Unavailable'),
			];

			if (is_array($request))
			{
				$rows +=
				[
					'Recharge opportunity'            => self::opportunity($request['opportunity'] ?? null),
					'Requested / first observed'      => self::time($request['requestedAt'] ?? null),
					'Production first observed'       => self::time($request['startedAt'] ?? null),
					'Hot water priority last changed' => self::time($request['lastHotWaterSample'] ?? null),
					'Priority change before request'  => self::time($request['afterPrioritySample'] ?? null),
				];
			}

			return $rows +
			[
				'Last control check'                => self::time($state['lastEvaluatedAt'] ?? null),
				'Last controller poll'              => self::time($state['lastPolledAt'] ?? null),
				'Latest live telemetry change'      => self::time($state['lastTelemetryAt'] ?? null),
				'Comfort mode confirmed'            => self::time($state['confirmedAt'] ?? null),
				'Last recharge completed'           => self::time($state['lastCompletedAt'] ?? null),
				'Controller completion change'      => self::time($state['lastCompletedPriorityAt'] ?? null),
				'Last completed overnight recharge' => self::date($completed['overnight'] ?? null),
				'Last completed afternoon recharge' => self::date($completed['afternoon'] ?? null),
				'Retry delay until'                 => self::time($state['retryAfter'] ?? null, 'No retry delay'),
			];
		}

		private static function mode(mixed $value, string $missing = 'Not confirmed') : string
		{
			if ($value === null)
			{
				return $missing;
			}

			return match (true)
			{
				in_array($value, [0, 0.0, '0'], true) => 'Economy',
				in_array($value, [1, 1.0, '1'], true) => 'Standard / normal',
				default => 'Unknown',
			};
		}

		private static function hasTime(mixed $value) : bool
		{
			return is_numeric($value) && is_finite((float)$value) && $value > 0 && $value <= 253402300799;
		}

		private static function time(mixed $value, string $missing = 'Not recorded') : string
		{
			if (!self::hasTime($value))
			{
				return $missing;
			}

			try
			{
				return CarbonImmutable::createFromTimestamp((int)$value)->setTimezone('Europe/London')->format('j M Y, H:i:s T');
			}
			catch (Throwable)
			{
				return $missing;
			}
		}

		private static function date(mixed $value) : string
		{
			if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
			{
				return 'Not recorded';
			}

			try
			{
				$date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/London');

				return $date && $date->toDateString() === $value ? $date->format('j M Y') : 'Not recorded';
			}
			catch (Throwable)
			{
				return 'Not recorded';
			}
		}

		private static function opportunity(mixed $value) : string
		{
			if ($value === null)
			{
				return 'Observed existing recharge';
			}

			if (!is_string($value) || !preg_match('/^(overnight|afternoon):(\d{4}-\d{2}-\d{2})$/', $value, $parts))
			{
				return 'Not recorded';
			}

			return ucfirst($parts[1]).' · '.self::date($parts[2]);
		}
	}
