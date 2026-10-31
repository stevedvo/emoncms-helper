<?php
	namespace App\Services;

	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;

	class RadiatorHeatDemand
	{
		public const SETTING_KEY = 'radiatorHeatDemand';

		public static function record(?bool $required, ?CarbonImmutable $holdUntil = null, bool $cancelBoostHold = false) : void
		{
			$now = CarbonImmutable::now();
			$reason = null;
			$previousRequired = self::storedDemand($reason, $previous);
			$previousHold = $previous['boostHoldUntil'] ?? null;
			$boostHoldUntil = !$cancelBoostHold && $required !== null && $previousRequired !== null && is_int($previousHold) && $previousHold > $now->getTimestamp() ? $previousHold : null;

			if (!$cancelBoostHold && $required === true && $holdUntil !== null && $holdUntil->isAfter($now))
			{
				$boostHoldUntil = max($boostHoldUntil ?? 0, $holdUntil->getTimestamp());
			}

			if ($boostHoldUntil !== $previousHold)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => 'info',
					'message'    => $boostHoldUntil === null ? 'Radiator boost hold ended; using temperature demand.' : 'Radiator boost hold active until '.$now->setTimestamp($boostHoldUntil)->setTimezone(TariffPeriods::ZONE)->format('Y-m-d H:i:s T').'; Hive will retain radiator demand during space heating.',
				]);
			}

			Setting::updateOrCreate(['key' => self::SETTING_KEY], ['value' => json_encode(
			[
				'required'       => $required,
				'calculatedAt'   => $now->getTimestamp(),
				'boostHoldUntil' => $boostHoldUntil,
			], JSON_THROW_ON_ERROR)]);
		}

		public static function calculate(float $temperature, float $threshold) : bool
		{
			$reason = null;
			$previous = self::storedDemand($reason);
			$margin = ControlSettings::get('hive.radiatorHysteresis');
			$onThreshold = round($threshold - $margin, 6);
			$offThreshold = round($threshold + $margin, 6);

			$required = self::evaluate($temperature, $threshold, $previous);

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => 'info',
				'message'    => 'Radiator demand: temperature '.$temperature.'; adjusted threshold '.$threshold.'; hysteresis +/-'.$margin.'; on at '.$onThreshold.'; off at '.$offThreshold.'; previous '.json_encode($previous).'; required '.json_encode($required).($previous === null ? '; initial decision: '.$reason : '').'.',
			]);

			return $required;
		}

		public static function evaluate(float $temperature, float $threshold, ?bool $previous) : bool
		{
			$margin = ControlSettings::get('hive.radiatorHysteresis');

			if ($previous === null || $margin == 0)
			{
				return $temperature <= $threshold;
			}

			return $previous ? $temperature < round($threshold + $margin, 6) : $temperature <= round($threshold - $margin, 6);
		}

		public static function previous() : ?bool
		{
			$reason = null;

			return self::storedDemand($reason);
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
				$required = self::storedDemand($reason, $decision);

				if ($required !== null)
				{
					if (is_int($decision['boostHoldUntil'] ?? null) && $decision['boostHoldUntil'] > CarbonImmutable::now()->getTimestamp() && !self::peakBlocksHold(CarbonImmutable::now()))
					{
						return true;
					}

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

		public static function peakBlocksHold(CarbonImmutable $time, ?bool $peakImport = null) : bool
		{
			// A dated cheap offer can outlast its early boost finish inside the normal daily peak.
			return ($peakImport ?? TariffPeriods::peakAt($time)) && (TariffPeriods::extraHeatingTypeAt($time) === 'peak' || TariffPeriods::extraTypeAt($time) !== 'cheap');
		}

		private static function storedDemand(?string &$reason, ?array &$decision = null) : ?bool
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
