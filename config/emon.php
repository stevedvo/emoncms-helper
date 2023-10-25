<?php
	return [
		'emonHpLocalEndpoint'     => env("EMON_HP_LOCAL_ENDPOINT", "http://emonhp.local/feed"),
		'emonHpLocalReadKey'      => env("EMON_HP_LOCAL_READ_KEY"),
		'emonHpLocalReadWriteKey' => env("EMON_HP_LOCAL_READWRITE_KEY"),
		'emonCmsEndpoint'         => "https://emoncms.org/feed",
		'emonCmsReadKey'          => env("EMON_CMS_READ_KEY"),
		'emonCmsReadWriteKey'     => env("EMON_CMS_READWRITE_KEY"),
		'emonSyncPeriodHours'     => env("EMON_SYNC_PERIOD_HOURS", "3"),
	];
