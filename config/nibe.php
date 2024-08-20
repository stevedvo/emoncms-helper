<?php
	return [
		'clientId'        => env("NIBE_CLIENT_ID"),
		'clientSecret'    => env("NIBE_CLIENT_SECRET"),
		'systemId'        => env("NIBE_SYSTEM_ID"),
		'deviceId'        => env("NIBE_DEVICE_ID"),
		'tokenUrl'        => "https://api.myuplink.com/oauth/token",
		'functionUrl'     => "https://api.myuplink.com/v2",
		'dmOverride'      => env("NIBE_DM_OVERRIDE", false),
		'dmTarget'        => env("NIBE_DM_TARGET", -180),
		'dmTargetOff'     => env("NIBE_DM_TARGET_OFF", 30),
		'dmTargetOffTemp' => env("NIBE_DM_TARGET_OFF_TEMP", 12),
		'minutesToDm'     => env("NIBE_MINUTES_TO_DM", 30),
		'offsetFactor'    => env("NIBE_OFFSET_FACTOR", 2),
		'offsetMinimum'   => env("NIBE_OFFSET_MINIMUM", -10),
		'offsetMaximum'   => env("NIBE_OFFSET_MAXIMUM", 10),
		'tempFreqMin'     => env("NIBE_TEMP_FREQ_MIN", 6),
	];
