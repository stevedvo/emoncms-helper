<?php
	namespace App\APIs;

	use Throwable;

	class EmonAPI
	{
		public static function getFeedList(string $environment) : array
		{
			try
			{
				static::ValidateEnvironment($environment);

				$url    = "";
				$apikey = "";

				switch ($environment)
				{
					case 'local':
					{
						$url    = config("emon.emonHpLocalEndpoint");
						$apikey = config("emon.emonHpLocalReadKey");
					}
					break;

					case 'remote':
					{
						$url    = config("emon.emonCmsEndpoint");
						$apikey = config("emon.emonCmsReadKey");
					}
					break;
				}

				$url.= "/list.json?apikey=".$apikey;

				$response = API::get($url)->send();

				return json_decode((string)$response->getBody(), true);
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}

		public static function ValidateEnvironment($environment)
		{
			if ($environment != "local" && $environment != "remote")
			{
				throw new Exception("Unknown environment");
			}
		}
	}
