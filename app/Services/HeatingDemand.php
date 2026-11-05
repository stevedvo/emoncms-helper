<?php
	namespace App\Services;

	class HeatingDemand
	{
		public static function radiatorAdjustments(?float $lowForecast, bool $boost, bool $peak) : array
		{
			$cold = 0;

			if ($lowForecast !== null)
			{
				if ($lowForecast < ControlSettings::get('nibe.dmTargetBoostTemp'))
				{
					$cold = ControlSettings::get('nibe.radColdForecastTempAdjust');
				}
				elseif ($lowForecast < ControlSettings::get('nibe.radCoolForecastTemp'))
				{
					$cold = ControlSettings::get('nibe.radCoolForecastTempAdjust');
				}
			}

			return
			[
				'cold'  => $cold,
				'boost' => $boost ? ControlSettings::get('nibe.radBoostTempAdjust') : 0,
				'peak'  => $peak ? ControlSettings::get('nibe.radPeakTempAdjust') : 0,
			];
		}

		public static function ufhThresholds(bool $boost, bool $peak) : array
		{
			$adjustment = $boost && !$peak ? ControlSettings::get('nibe.ufhBoostTempAdjust') : 0;

			return
			[
				'adjustment'   => $adjustment,
				'on'           => ControlSettings::get('nibe.ufhTempOn') + $adjustment,
				'intermittent' => ControlSettings::get('nibe.ufhTempIntermittent') + $adjustment,
			];
		}

		// Predict using the live target rules without recording a new radiator decision.
		public static function required(?float $rad, ?float $ufh, ?float $lowForecast, bool $boost, bool $peak, ?bool $previousRadiatorDemand, int $priority) : ?bool
		{
			$threshold = ControlSettings::get('nibe.loadCompTempOff') + array_sum(self::radiatorAdjustments($lowForecast, $boost, $peak));
			$radiators = $rad === null ? null : RadiatorHeatDemand::evaluate($rad, $threshold, $previousRadiatorDemand, $priority);
			$underfloor = $ufh === null ? null : $ufh <= self::ufhThresholds($boost, $peak)['intermittent'];

			if ($radiators === true || $underfloor === true)
			{
				return true;
			}

			return $radiators === null || $underfloor === null ? null : false;
		}
	}
