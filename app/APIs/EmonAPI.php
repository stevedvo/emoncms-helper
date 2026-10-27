<?php
	namespace App\APIs;

	use Exception;
	use Throwable;
	use JsonException;
	use UnexpectedValueException;
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
				case "local":
				{
					$url      = config("emon.emonHpLocalEndpoint");
					$readkey  = config("emon.emonHpLocalReadKey");
					$writekey = config("emon.emonHpLocalReadWriteKey");
				}
				break;

				case "remote":
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
			[$url, $readkey, $writekey] = static::ValidateEnvironment($environment);

			$url.= "/feed/data.json?apikey=".$readkey."&id=".$feedId."&start=".$startTime."&end=".$endTime."&interval=10";

			$response = API::get($url)->send();
			$body = (string)$response->getBody();
			$source = 'Emon '.$environment.' feed #'.$feedId.' response';
			$context = 'HTTP '.$response->getStatusCode().'; '.strlen($body).' bytes';

			if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300)
			{
				throw new UnexpectedValueException($source.' had an unexpected status ('.$context.').');
			}

			if (trim($body) === '')
			{
				throw new UnexpectedValueException($source.' was empty ('.$context.').');
			}

			try
			{
				$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
			}
			catch (JsonException $e)
			{
				throw new UnexpectedValueException($source.' contained invalid JSON ('.$context.'): '.$e->getMessage().'.', 0, $e);
			}

			if (!is_array($data) || !array_is_list($data) || !str_starts_with(ltrim($body), '['))
			{
				throw new UnexpectedValueException($source.' was not a JSON list of timestamp/value pairs ('.$context.').');
			}

			foreach ($data as $index => $sample)
			{
				if (!is_array($sample) || !array_is_list($sample) || count($sample) !== 2 || !is_numeric($sample[0]) || !is_finite((float)$sample[0]) || (!is_scalar($sample[1]) && $sample[1] !== null))
				{
					throw new UnexpectedValueException($source.' contained an invalid sample at index '.$index.'; expected a numeric timestamp and a scalar or null value ('.$context.').');
				}
			}

			return $data;
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
