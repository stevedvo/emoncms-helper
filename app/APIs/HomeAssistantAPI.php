<?php
	namespace App\APIs;

	use Throwable;
	use Illuminate\Support\Facades\Log;

	class HomeAssistantAPI
	{
		private ?string $baseUrl;

		public function __construct()
		{
			$this->baseUrl = config("homeAssistant.baseUrl");
		}

		public function adjustHiveThermostat(int $newTargetTemperature) : void
		{
			try
			{
				$url = $this->baseUrl.config("homeAssistant.adjustHiveThermostatUrl");
				$response = API::put($url)->json(['temperature' => $newTargetTemperature])->send();
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}
	}
