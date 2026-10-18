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

		/**
		 * Null means Hive should retain its existing priority-based behaviour.
		 */
		public static function current() : ?bool
		{
			$reason = null;

			if (config('nibe.dmOverride') === false || config('nibe.loadCompensationOn') === false)
			{
				$reason = 'DM override or load compensation is disabled';
			}
			else
			{
				$stored = Setting::firstWhere('key', self::SETTING_KEY)?->value;
				$decision = is_string($stored) ? json_decode($stored, true) : null;

				if (!is_array($decision) || !is_int($decision['calculatedAt'] ?? null))
				{
					$reason = 'decision is missing or invalid';
				}
				else
				{
					$ageSeconds = CarbonImmutable::now()->getTimestamp() - $decision['calculatedAt'];

					if ($ageSeconds < 0 || $ageSeconds > config('hive.radiatorDemandMaxAgeMinutes', 10) * 60)
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
	}
