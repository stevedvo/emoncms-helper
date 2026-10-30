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

			if (ControlSettings::get('weather.useForecast'))
			{
				// All forecast-enabled temperature branches currently select Cosy.
				$key = 'cosy';
			}
			else
			{
				if ($average < ControlSettings::get('nibe.tempFreqMin'))
				{
					return true;
				}

				if ($outdoor >= ControlSettings::get('nibe.dmTargetOffTemp'))
				{
					return false;
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
					return true;
				}
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
	}
