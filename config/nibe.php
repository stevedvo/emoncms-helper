<?php
	return [
		'clientId'     => env("NIBE_CLIENT_ID"),
		'clientSecret' => env("NIBE_CLIENT_SECRET"),
		'systemId'     => env("NIBE_SYSTEM_ID"),
		'tokenUrl'     => "https://api.nibeuplink.com/oauth/token",
		'functionUrl'  => "https://api.nibeuplink.com/api/v1",
	];
