<?php
	return [
		'clientId'        => env("NIBE_CLIENT_ID"),
		'clientSecret'    => env("NIBE_CLIENT_SECRET"),
		'systemId'        => env("NIBE_SYSTEM_ID"),
		'deviceId'        => env("NIBE_DEVICE_ID"),
		'tokenUrl'        => "https://api.myuplink.com/oauth/token",
		'functionUrl'     => "https://api.myuplink.com/v2",
		'dmOverride'      => env("NIBE_DM_OVERRIDE", false),
		'tempFreqMin'     => env("NIBE_TEMP_FREQ_MIN", 6),
		'internalApiAuth' => env("NIBE_INTERNAL_API_AUTH"),
	];
