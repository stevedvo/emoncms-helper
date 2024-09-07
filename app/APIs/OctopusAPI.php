<?php
	namespace App\APIs;

	use Throwable;
	use Illuminate\Support\Facades\Log;

	class OctopusAPI
	{
		private ?string $baseUrl;

		public function __construct()
		{
			$this->baseUrl = config("octopus.baseUrl");
		}

		public function getAgileRates() : array
		{
			try
			{
				$url = $this->baseUrl.config("octopus.agileRates");
				$response = API::get($url)->send();

				return json_decode((string)$response->getBody(), true);
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}
	}
