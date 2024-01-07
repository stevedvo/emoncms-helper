<?php
	namespace App\APIs;

	use Throwable;
	use Illuminate\Support\Facades\Log;

	class EmonAPI
	{
		public static function ValidateEnvironment(string $environment) : array
		{
			$url      = "";
			$readkey  = "";
			$writekey = "";

			switch ($environment)
			{
				case 'local':
				{
					$url      = config("emon.emonHpLocalEndpoint");
					$readkey  = config("emon.emonHpLocalReadKey");
					$writekey = config("emon.emonHpLocalReadWriteKey");
				}
				break;

				case 'remote':
				{
					$url      = config("emon.emonCmsEndpoint");
					$readkey  = config("emon.emonCmsReadKey");
					$writekey = config("emon.emonCmsReadWriteKey");
				}
				break;

				default:
				{
					throw new Exception("Unknown environment");
				}
				break;
			}

			return [$url, $readkey, $writekey];
		}

		public static function getFeedList(string $environment) : array
		{
			try
			{
				[$url, $readkey, $writekey] = static::ValidateEnvironment($environment);

				$url.= "/feed/list.json?apikey=".$readkey;

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
				[$url, $readkey, $writekey] = static::ValidateEnvironment($environment);

				$url.= "/feed/data.json?apikey=".$readkey."&id=".$feedId."&start=".$startTime."&end=".$endTime."&interval=10";

				$response = API::get($url)->send();

				return json_decode((string)$response->getBody(), true);
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}

		public static function postInputData(string $environment, string $timestamp, string $nodeName, string $json) : bool
		{
			try
			{
				[$url, $readkey, $writekey] = static::ValidateEnvironment($environment);

				$url.= "/input/post";

				$response = API::post($url)->formData(
				[
					'time'     => $timestamp,
					'node'     => $nodeName,
					'fulljson' => $json,
					'apikey'   => $writekey,
				])->send();

				$body = json_decode((string)$response->getBody(), true);

				return (is_array($body) && isset($body['success']) && $body['success'] == "true");
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}
	}
