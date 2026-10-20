<?php
	namespace App\Services;

	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;

	class RadiatorHeatDemand
	{
		public const SETTING_KEY = 'radiatorHeatDemand';

		public static function record(?bool $required) : void
		{
			Setting::updateOrCreate(['key' => self::SETTING_KEY], ['value' => json_encode(
			[
				'required'     => $required,
				'calculatedAt' => CarbonImmutable::now()->getTimestamp(),
			], JSON_THROW_ON_ERROR)]);
		}

		public static function calculate(float $temperature, float $threshold) : bool
		{
			$reason = null;
			$previous = self::storedDemand($reason);
			$margin = ControlSettings::get('hive.radiatorHysteresis');
			$onThreshold = round($threshold - $margin, 6);
			$offThreshold = round($threshold + $margin, 6);

			// Initialise at the original threshold when no recent decision can be retained.
			if ($previous === null || $margin == 0)
			{
				$required = $temperature <= $threshold;
			}
			else
			{
				$required = $previous ? $temperature < $offThreshold : $temperature <= $onThreshold;
			}

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => 'info',
				'message'    => 'Radiator demand: temperature '.$temperature.'; adjusted threshold '.$threshold.'; hysteresis +/-'.$margin.'; on at '.$onThreshold.'; off at '.$offThreshold.'; previous '.json_encode($previous).'; required '.json_encode($required).($previous === null ? '; initial decision: '.$reason : '').'.',
			]);

			return $required;
		}

		/**
		 * Null means Hive should retain its existing priority-based behaviour.
		 */
		public static function current() : ?bool
		{
			$reason = null;

			if (ControlSettings::get('nibe.dmOverride') === false || ControlSettings::get('nibe.loadCompensationOn') === false)
			{
				$reason = 'DM override or load compensation is disabled';
			}
			else
			{
				$required = self::storedDemand($reason);

				if ($required !== null)
				{
					return $required;
				}
			}

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => 'warning',
				'message'    => 'Radiator heat demand: '.$reason.'; using priority-based Hive control.',
			]);

			return null;
		}

		private static function storedDemand(?string &$reason) : ?bool
		{
			$stored = Setting::firstWhere('key', self::SETTING_KEY)?->value;
			$decision = is_string($stored) ? json_decode($stored, true) : null;

			if (!is_array($decision) || !is_int($decision['calculatedAt'] ?? null))
			{
				$reason = 'decision is missing or invalid';

				return null;
			}

			$ageSeconds = CarbonImmutable::now()->getTimestamp() - $decision['calculatedAt'];

			if ($ageSeconds < 0 || $ageSeconds > ControlSettings::get('hive.radiatorDemandMaxAgeMinutes') * 60)
			{
				$reason = 'decision is stale or dated in the future';
			}
			elseif (!is_bool($decision['required'] ?? null))
			{
				$reason = 'radiator temperature demand is unavailable';
			}
			else
			{
				return $decision['required'];
			}

			return null;
		}
	}
