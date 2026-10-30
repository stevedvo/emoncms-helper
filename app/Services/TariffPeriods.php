<?php
	namespace App\Services;

	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use Carbon\CarbonInterface;
	use Illuminate\Support\Facades\DB;
	use Illuminate\Validation\ValidationException;

	class TariffPeriods
	{
		public const KEY = 'octopusTariffPeriods';
		public const ZONE = 'Europe/London';

		public static function settings() : array
		{
			$stored = Setting::where('key', self::KEY)->value('value');
			$values = $stored === null ? [] : json_decode($stored, true, 512, JSON_THROW_ON_ERROR);

			return array_replace_recursive(config('octopus-periods'), $values);
		}

		public static function revision(array $values) : string
		{
			return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
		}

		public static function clock(CarbonInterface $day, string $time) : CarbonImmutable
		{
			return CarbonImmutable::instance($day)->setTimezone(self::ZONE)->setTimeFromTimeString($time)->startOfMinute();
		}

		public static function window(CarbonInterface $day, array $times) : array
		{
			$start = self::clock($day, $times['start']);
			$end = self::clock($day, $times['end']);

			if ($end->lessThanOrEqualTo($start))
			{
				$end = $end->addDay();
			}

			return [$start, $end];
		}

		public static function extraTypeAt(CarbonInterface $time, ?array $settings = null) : ?string
		{
			foreach (($settings ?? self::settings())['extra'] as $event)
			{
				if ($time->greaterThanOrEqualTo(CarbonImmutable::parse($event['start'], self::ZONE)) && $time->lessThan(CarbonImmutable::parse($event['end'], self::ZONE)))
				{
					return $event['type'];
				}
			}

			return null;
		}

		public static function peakAt(CarbonInterface $time) : bool
		{
			$settings = self::settings();
			$extra = self::extraTypeAt($time, $settings);

			if ($extra !== null)
			{
				return $extra === 'peak';
			}

			$local = CarbonImmutable::instance($time)->setTimezone(self::ZONE);
			$period = $settings['daily']['peak'];
			$startOffset = ControlSettings::get('nibe.peakImportStartOffsetMinutes');
			$endOffset = ControlSettings::get('nibe.peakImportEndOffsetMinutes');

			foreach ([-1, 0, 1] as $day)
			{
				[$start, $end] = self::window($local->addDays($day), $period);
				$start = $start->addMinutes($startOffset);
				$end = $end->addMinutes($endOffset);

				if ($local->betweenIncluded($start, $end))
				{
					return true;
				}
			}

			return false;
		}

		public static function nextCosyWindows(CarbonInterface $time, bool $includeRunning = false) : array
		{
			$local = CarbonImmutable::instance($time)->setTimezone(self::ZONE);
			$settings = self::settings();
			$windows = [];

			foreach (['morning', 'afternoon', 'evening'] as $name)
			{
				foreach ([-1, 0, 1, 2] as $day)
				{
					[$start, $end] = self::window($local->addDays($day), $settings['daily'][$name]);
					$adjustedStart = $start->addMinutes(ControlSettings::get('octopus.offsetToOn'));
					$adjustedEnd = $end->addMinutes(ControlSettings::get('octopus.offsetToOff'));

					if ($adjustedEnd->lessThanOrEqualTo($adjustedStart))
					{
						continue;
					}

					if ($includeRunning ? $adjustedEnd->isAfter($local) : $start->greaterThanOrEqualTo($local))
					{
						$windows[] =
						[
							'average_cost' => 13.64,
							'start'        => $adjustedStart->getTimestamp(),
							'end'          => $adjustedEnd->getTimestamp(),
						];

						break;
					}
				}
			}

			return $windows;
		}

		public static function cheapWindows(CarbonImmutable $from, CarbonImmutable $until) : array
		{
			$settings = self::settings();
			$windows = [];
			$local = $from->setTimezone(self::ZONE);

			foreach ([-1, 0, 1] as $day)
			{
				foreach (['morning', 'afternoon', 'evening'] as $name)
				{
					$windows[] = self::window($local->addDays($day), $settings['daily'][$name]);
				}
			}

			foreach ($settings['extra'] as $event)
			{
				if ($event['type'] === 'cheap')
				{
					$windows[] = [CarbonImmutable::parse($event['start'], self::ZONE), CarbonImmutable::parse($event['end'], self::ZONE)];
				}
			}

			return array_values(array_filter($windows, fn($window) => $window[1]->isAfter($from) && $window[0]->isBefore($until)));
		}

		public static function hotWaterCanFinish(CarbonImmutable $time, int $minutes) : bool
		{
			$from = $time->setTimezone(self::ZONE)->startOfMinute();
			$until = $from->addMinutes($minutes);
			$settings = self::settings();
			$peaks = [];

			foreach ([-1, 0, 1] as $day)
			{
				$peaks[] = self::window($from->addDays($day), $settings['daily']['peak']);
			}

			foreach ($peaks as [$start, $end])
			{
				$segments = [[$start, $end]];

				// A dated cheap offer replaces the normal tariff during its exact interval.
				foreach ($settings['extra'] as $event)
				{
					if ($event['type'] !== 'cheap')
					{
						continue;
					}

					$cheapStart = CarbonImmutable::parse($event['start'], self::ZONE);
					$cheapEnd = CarbonImmutable::parse($event['end'], self::ZONE);
					$remaining = [];

					foreach ($segments as [$left, $right])
					{
						if ($cheapEnd->lessThanOrEqualTo($left) || $cheapStart->greaterThanOrEqualTo($right))
						{
							$remaining[] = [$left, $right];
							continue;
						}

						if ($left->isBefore($cheapStart))
						{
							$remaining[] = [$left, $cheapStart];
						}

						if ($right->isAfter($cheapEnd))
						{
							$remaining[] = [$cheapEnd, $right];
						}
					}

					$segments = $remaining;
				}

				foreach ($segments as [$left, $right])
				{
					if ($from->isBefore($right) && $until->isAfter($left))
					{
						return false;
					}
				}
			}

			foreach ($settings['extra'] as $event)
			{
				if ($event['type'] === 'peak' && $from->isBefore(CarbonImmutable::parse($event['end'], self::ZONE)) && $until->isAfter(CarbonImmutable::parse($event['start'], self::ZONE)))
				{
					return false;
				}
			}

			return true;
		}

		public static function save(array $values, string $revision, int $userId) : void
		{
			DB::transaction(function() use ($values, $revision, $userId)
			{
				Setting::where('key', self::KEY)->lockForUpdate()->first();
				$current = self::settings();

				if (!hash_equals(self::revision($current), $revision))
				{
					throw ValidationException::withMessages(['tariff_revision' => 'Tariff periods changed in another session. Reload this page before saving again.']);
				}

				if ($current === $values)
				{
					return;
				}

				Setting::updateOrCreate(['key' => self::KEY], ['value' => json_encode($values, JSON_THROW_ON_ERROR)]);
				$schedule = json_decode(Setting::where('key', 'agile_schedule')->value('value') ?? 'null', true);

				if (is_array($schedule))
				{
					$schedule['cosy'] = self::nextCosyWindows(CarbonImmutable::now(), true);
					Setting::updateOrCreate(['key' => 'agile_schedule'], ['value' => json_encode($schedule, JSON_THROW_ON_ERROR)]);
				}

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => 'info',
					'message'    => 'User #'.$userId.' changed tariff periods from '.json_encode($current).' to '.json_encode($values).'.',
				]);
			});
		}
	}
