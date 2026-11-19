<?php
	namespace App\Services;

	use Carbon\CarbonImmutable;
	use UnexpectedValueException;

	class HeatingBoostSchedule
	{
		public static function activeAt(array $schedules, float $outdoor, float $average, CarbonImmutable $time) : bool
		{
			$extra = TariffPeriods::extraHeatingTypeAt($time);

			if ($extra !== null)
			{
				return $extra === 'cheap';
			}

			$key = self::scheduleKey($outdoor, $average);

			if ($key === null || $key === 'continuous')
			{
				return $key === 'continuous';
			}

			if (!isset($schedules[$key]) || !is_array($schedules[$key]))
			{
				throw new UnexpectedValueException('Heating boost schedule is missing '.$key.'.');
			}

			foreach ($schedules[$key] as $window)
			{
				$start = CarbonImmutable::parse($window['start']);
				$end = CarbonImmutable::parse($window['end']);

				if ($time->isAfter($start) && $time->isBefore($end))
				{
					return true;
				}
			}

			return false;
		}

		// The early boost finish reduces intensity; an already-open radiator zone can use the full cheap period.
		public static function radiatorHoldUntilAt(array $schedules, float $outdoor, float $average, CarbonImmutable $time) : ?CarbonImmutable
		{
			$extra = TariffPeriods::extraHeatingTypeAt($time);
			$until = null;

			if ($extra === 'peak')
			{
				return null;
			}

			if ($extra === 'cheap')
			{
				foreach (TariffPeriods::settings()['extra'] as $event)
				{
					if ($event['type'] !== 'cheap')
					{
						continue;
					}

					$actualEnd = CarbonImmutable::parse($event['end'], TariffPeriods::ZONE);
					[$start, $end] = TariffPeriods::heatingWindow(CarbonImmutable::parse($event['start'], TariffPeriods::ZONE), $actualEnd, 'cheap');

					if ($time->greaterThanOrEqualTo($start) && $time->isBefore($end))
					{
						$candidate = $end->max($actualEnd);
						$until = $until === null ? $candidate : $until->max($candidate);
					}
				}

				return $until;
			}

			$key = self::scheduleKey($outdoor, $average);

			foreach ($schedules[$key ?? ''] ?? [] as $window)
			{
				$start = CarbonImmutable::parse($window['start']);
				$end = CarbonImmutable::parse($window['end']);

				if ($time->isAfter($start) && $time->isBefore($end))
				{
					// Older generated schedules have no tariff_end; recover it using their configured end offset.
					$actualEnd = isset($window['tariff_end']) ? CarbonImmutable::parse($window['tariff_end']) : $end->subMinutes(ControlSettings::get('octopus.offsetToOff'));
					$candidate = $end->max($actualEnd);
					$until = $until === null ? $candidate : $until->max($candidate);
				}
			}

			return $until;
		}

		private static function scheduleKey(float $outdoor, float $average) : ?string
		{
			if (ControlSettings::get('weather.useForecast'))
			{
				// All forecast-enabled temperature branches currently select Cosy.
				$key = 'cosy';
			}
			else
			{
				if ($average < ControlSettings::get('nibe.tempFreqMin'))
				{
					return 'continuous';
				}

				if ($outdoor >= ControlSettings::get('nibe.dmTargetOffTemp'))
				{
					return null;
				}

				if ($average >= ControlSettings::get('nibe.runLevel1Temp') || $outdoor >= ControlSettings::get('nibe.runLevel1Temp'))
				{
					$key = 'cheapest_3_hours';
				}
				elseif ($average >= ControlSettings::get('nibe.runLevel2Temp') || $outdoor >= ControlSettings::get('nibe.runLevel2Temp'))
				{
					$key = 'cheapest_6_hours';
				}
				else
				{
					return 'continuous';
				}
			}

			return $key;
		}
	}
