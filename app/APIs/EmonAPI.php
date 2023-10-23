<?php
	namespace App\APIs;

	use Throwable;

	class EmonAPI
	{
		public static function ValidateEnvironment(string $environment) : array
		{
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

				default:
				{
					throw new Exception("Unknown environment");
				}
				break;
			}

			return [$url, $apikey];
		}

		public static function getFeedList(string $environment) : array
		{
			try
			{
				[$url, $apikey] = static::ValidateEnvironment($environment);

				$url.= "/list.json?apikey=".$apikey;

				$response = API::get($url)->send();

				return json_decode((string)$response->getBody(), true);
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}

		public static function getFeedData(string $environment, int $feedId, string $startTime, string $endTime) : array
		{
			try
			{
				[$url, $apikey] = static::ValidateEnvironment($environment);

				$url.= "/data.json?apikey=".$apikey."&id=".$feedId."&start=".$startTime."&end=".$endTime."&interval=10";

				$response = API::get($url)->send();

				return json_decode((string)$response->getBody(), true);
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}
	}
