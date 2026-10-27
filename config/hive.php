<?php
	return [
		'radiatorDemandMaxAgeMinutes' => env("HIVE_RADIATOR_DEMAND_MAX_AGE_MINUTES", 10),
		'targetOnTemp'                => env("HIVE_TARGET_ON_TEMP", 30),
		'targetOffTemp'               => env("HIVE_TARGET_OFF_TEMP", 16),
	];
