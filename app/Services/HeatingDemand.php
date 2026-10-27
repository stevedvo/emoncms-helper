<?php
	namespace App\Services;

	class HeatingDemand
	{
		public static function radiatorAdjustments(?float $lowForecast, bool $boost, bool $peak) : array
		{
			$cold = $lowForecast === null ? 0 : ($lowForecast < ControlSettings::get('nibe.dmTargetBoostTemp') ? 2 : ($lowForecast < 3 ? 1 : 0));

			return
			[
				'cold'  => $cold,
				'boost' => $boost ? 1 : 0,
				'peak'  => $peak ? -2 : 0,
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
		public static function required(?float $rad, ?float $ufh, ?float $lowForecast, bool $boost, bool $peak, ?bool $previousRadiatorDemand) : ?bool
		{
			$threshold = ControlSettings::get('nibe.loadCompTempOff') + array_sum(self::radiatorAdjustments($lowForecast, $boost, $peak));
			$radiators = $rad === null ? null : RadiatorHeatDemand::evaluate($rad, $threshold, $previousRadiatorDemand);
			$underfloor = $ufh === null ? null : $ufh <= self::ufhThresholds($boost, $peak)['intermittent'];

			if ($radiators === true || $underfloor === true)
			{
				return true;
			}

			return $radiators === null || $underfloor === null ? null : false;
		}
	}
