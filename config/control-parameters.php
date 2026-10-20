<?php
	// Only these operating parameters can be edited through the control panel.
	// Credentials, connection details, and runtime state are deliberately excluded.
	$number = static fn ($group, $label, $default, $min, $max, $unit, $help, $integer = false) =>
	[
		'group' => $group, 'label' => $label, 'default' => $default,
		'type'  => $integer ? 'integer' : 'number', 'min' => $min, 'max' => $max,
		'unit'  => $unit, 'help' => $help,
	];

	$toggle = static fn ($group, $label, $default, $help) =>
	[
		'group' => $group, 'label' => $label, 'default' => $default,
		'type' => 'boolean', 'unit' => '', 'help' => $help,
	];

	return [
		'nibe.dmOverride'                     => $toggle('Heating', 'Automatic heating control', false, 'Allow the five-minute control calculation to adjust NIBE flow settings. This does not disable hot-water or Hive commands.'),
		'nibe.loadCompensationOn'             => $toggle('Room temperatures', 'Use room temperatures', true, 'Use radiator temperature bands and the UFH minimum-temperature rules.'),
		'nibe.allowBoosts'                    => $toggle('Heating', 'Allow scheduled boost promotion', false, 'Promote the heating mode during boost windows. Room-band boost adjustments still apply when this is off.'),
		'nibe.cheapMode'                      => $toggle('Flow settings', 'Restrict flow settings (cheap mode)', false, 'Apply the restrictive offset limit across modes. This does not test the current electricity price.'),
		'nibe.dmTarget'                       => $number('Heating', 'Normal degree-minute target', -180, -2000, 2000, 'DM', 'Target used by both intermittent and on modes.', true),
		'nibe.dmTargetOff'                    => $number('Heating', 'Off degree-minute target', 30, -2000, 2000, 'DM', 'Target when heating is not required. Hot-water priority reduces this by 60.', true),
		'nibe.dmTargetBoost'                  => $number('Heating', 'Boost degree-minute target', -360, -2000, 2000, 'DM', 'Boost target. ExtraBoost uses normal + boost + 90.', true),
		'nibe.dmTargetCooling'                => $number('Heating', 'Cooling degree-minute target', 75, -2000, 2000, 'DM', 'Target while the heat pump is in cooling mode.', true),
		'nibe.coolingStartTemp'               => $number('Heating', 'Cooling outdoor threshold', 24, 10, 40, '°C', 'Choose cooling when NIBE average outdoor temperature is above this value.'),
		'nibe.loadCompTempOff'                => $number('Room temperatures', 'Radiators: off threshold', 21.5, 5, 35, '°C', 'Above this adjusted temperature, the radiator zone does not request heat.'),
		'nibe.loadCompTempIntermittent'       => $number('Room temperatures', 'Radiators: intermittent threshold', 21, 5, 35, '°C', 'Above this adjusted temperature, at least intermittent heating is sufficient.'),
		'nibe.loadCompTempOn'                 => $number('Room temperatures', 'Radiators: on threshold', 20, 5, 35, '°C', 'Above this adjusted temperature, at least on is sufficient.'),
		'nibe.loadCompTempLevel1'             => $number('Room temperatures', 'Radiators: boost threshold', 19, 5, 35, '°C', 'At or below this adjusted temperature, request extraBoost.'),
		'nibe.ufhTempIntermittent'            => $number('Room temperatures', 'UFH: allow heating at', 20, 5, 30, '°C', 'Normal threshold: at or below this temperature, ensure at least intermittent heating. The preheat margin raises it during boost windows outside peak import.'),
		'nibe.ufhTempOn'                      => $number('Room temperatures', 'UFH: continuous heating at', 19.5, 5, 30, '°C', 'Normal threshold: at or below this temperature, ensure at least on. The preheat margin raises it during boost windows outside peak import.'),
		'nibe.ufhBoostTempAdjust'             => $number('Room temperatures', 'UFH: cheaper-window preheat margin', 1, 0, 3, '°C', 'Add this to both UFH thresholds during boost windows outside peak import. Normal thresholds remain the minimum protection. Zero disables this margin, not the separate scheduled mode promotion.'),
		'nibe.ufhBoostTempShortfall'          => $number('Room temperatures', 'UFH: boost shortfall', 1, 0.1, 5, '°C', 'Request at least boost when UFH is more than this far below the allow-heating threshold plus any active preheat margin. Applies in all periods; defrost and cooling rules still take precedence.'),
		'nibe.roomTemperatureLookbackMinutes' => $number('Room temperatures', 'Room sensor history', 180, 1, 1440, 'min', 'How far back to search Emon for the latest usable room temperature.', true),
		'nibe.minutesToDm'                    => $number('Flow settings', 'Degree-minute steering horizon', 30, 1, 240, 'min', 'Estimated time over which to steer toward the degree-minute target.', true),
		'nibe.offsetFactor'                   => $number('Flow settings', 'Temperature change per offset step', 2, 0.1, 10, '°C', 'Estimated calculated-flow temperature change for each offset step.'),
		'nibe.offsetMinimum'                  => $number('Flow settings', 'Minimum offset', -10, -10, 10, 'steps', 'Lower bound for normal flow offset adjustments.', true),
		'nibe.offsetMaximum'                  => $number('Flow settings', 'Maximum offset', 10, -10, 10, 'steps', 'Upper bound for normal flow offset adjustments.', true),
		'nibe.offsetMaxInt'                   => $number('Flow settings', 'Intermittent offset limit', 0, -10, 10, 'steps', 'Baseline maximum offset in intermittent mode; the existing dynamic limit still applies.', true),
		'nibe.cheapModeOffsetMax'             => $number('Flow settings', 'Cheap-mode offset limit', -4, -10, 10, 'steps', 'Baseline maximum offset when restrictive cheap mode is enabled.', true),
		'weather.useForecast'                 => $toggle('Weather and boosts', 'Use weather forecasts', true, 'Use forecast summaries in mode and boost selection, and publish them to Emon.'),
		'weather.lookAheadHours'              => $number('Weather and boosts', 'Forecast starting offset', 6, 0, 24, 'hours', 'The weighted forecast starts this many hours ahead, rather than averaging the intervening hours.', true),
		'nibe.runLevel1Temp'                  => $number('Weather and boosts', 'Warm forecast threshold', 12, -20, 35, '°C', 'A higher six-sample high forecast suppresses boosts and lowers room-temperature bands.'),
		'nibe.runLevel2Temp'                  => $number('Weather and boosts', 'Cool outdoor threshold', 9, -20, 35, '°C', 'Used by boost selection without forecasts; forecast-enabled branches currently choose Cosy.'),
		'nibe.tempFreqMin'                    => $number('Weather and boosts', 'Cold outdoor threshold', 6, -30, 35, '°C', 'Used by boost selection without forecasts; forecast-enabled branches currently choose Cosy.'),
		'nibe.dmTargetOffTemp'                => $number('Weather and boosts', 'No-forecast boost cutoff', 12, -20, 35, '°C', 'Current-outdoor cutoff in the forecast-disabled boost-selection path.'),
		'nibe.dmTargetBoostTemp'              => $number('Weather and boosts', 'Cold forecast adjustment threshold', 3, -30, 20, '°C', 'Below this low forecast, shift room bands up by 2°C and retain the peak minimum-mode rule.'),
		'nibe.defrostDewpointMin'             => $number('Defrost reduction', 'Minimum dew point', -3, -30, 20, '°C', 'Strict lower dew-point threshold for the evaporator-based cap at on.'),
		'nibe.defrostDewpointMax'             => $number('Defrost reduction', 'Maximum dew point', 5, -30, 30, '°C', 'Strict upper dew-point threshold for the evaporator-based cap at on.'),
		'nibe.defrostEvaporatorMax'           => $number('Defrost reduction', 'Evaporator threshold', -15, -50, 10, '°C', 'An hourly minimum below this can cap demand. A further 5°C below triggers the cap regardless of dew point.'),
		'hive.targetOnTemp'                   => $number('Hive', 'Radiator zone on target', 30, 5, 32, '°C', 'Requested during radiator heating demand, fallback heating, or cooling.', true),
		'hive.targetOffTemp'                  => $number('Hive', 'Radiator zone off target', 16, 5, 32, '°C', 'Requested during UFH-only heating, idle, or hot-water production.', true),
		'hive.radiatorDemandMaxAgeMinutes'    => $number('Hive', 'Radiator decision maximum age', 10, 5, 180, 'min', 'Maximum age of the control decision, not the sensor reading. Older decisions fall back to priority-based control.', true),
		'emon.emonSyncPeriodHours'            => $number('Data maintenance', 'Emon comparison history', 3, 1, 48, 'hours', 'Rolling feed-comparison window. Historical backlog upload remains unfinished.', true),
		'logs.infoRetentionDays'              => $number('Data maintenance', 'Keep information logs', 30, 1, 3650, 'days', 'Retention period for information-level activity logs.', true),
		'logs.warningRetentionDays'           => $number('Data maintenance', 'Keep warning logs', 60, 1, 3650, 'days', 'Retention period for warning-level activity logs.', true),
		'logs.errorRetentionDays'             => $number('Data maintenance', 'Keep error logs', 90, 1, 3650, 'days', 'Retention period for error-level activity logs.', true),
		'logs.apiRetentionDays'               => $number('Data maintenance', 'Keep API logs', 90, 1, 3650, 'days', 'Retention period for API request logs.', true),
		'nibe.boostMaxCost'                   => $number('Legacy parameters', 'Boost maximum cost (unused)', 10, -100, 500, 'p/kWh', 'Stored for compatibility. The current boost logic does not enforce this price limit.'),
		'nibe.minRadZoneTemp'                 => $number('Legacy parameters', 'Minimum radiator temperature (unused)', 19, 5, 35, '°C', 'Stored for compatibility. The separate minimum-radiator-temperature rule is disabled.'),
	];
