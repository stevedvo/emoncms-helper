<?php
	namespace App\APIs;

	use Exception;
	use Throwable;
	use App\Models\NibeParameter;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Support\Facades\Log;

	class NibeAPI
	{
		private ?string  $nibeClientId;
		private ?string  $nibeClientSecret;
		private ?string  $nibeSystemId;
		private ?string  $nibeDeviceId;
		private ?Setting $nibeTokenCurrent;
		private ?Setting $nibeTokenExpiry;
		private ?Setting $nibeTokenRefresh;
		private ?string  $nibeTokenUrl;
		private ?string  $nibeFunctionUrl;

		public function __construct()
		{
			$settings = Setting::all();

			$this->nibeClientId     = config("nibe.clientId");
			$this->nibeClientSecret = config("nibe.clientSecret");
			$this->nibeSystemId     = config("nibe.systemId");
			$this->nibeDeviceId     = config("nibe.deviceId");
			$this->nibeTokenCurrent = $settings->where("key", "nibe_token_current")->first();
			$this->nibeTokenExpiry  = $settings->where("key", "nibe_token_expiry")->first();
			$this->nibeTokenRefresh = $settings->where("key", "nibe_token_refresh")->first();
			$this->nibeTokenUrl     = config("nibe.tokenUrl");
			$this->nibeFunctionUrl  = config("nibe.functionUrl");

			if (is_null($this->nibeTokenCurrent) || is_null($this->nibeTokenExpiry) || is_null($this->nibeTokenRefresh))
			{
				throw new Exception("Invalid NIBE token parameters");
			}

			$this->verifyAccessToken();
		}

		private function verifyAccessToken() : void
		{
			try
			{
				$now = CarbonImmutable::now();
				$tokenExpiry = CarbonImmutable::createFromTimestamp($this->nibeTokenExpiry->value);

				if ($tokenExpiry->isBefore($now))
				{
					if (is_null($this->nibeClientId) || is_null($this->nibeClientSecret))
					{
						throw new Exception("Cannot renew NIBE access token: invalid Client ID/Secret");
					}

					$response = API::post($this->nibeTokenUrl)->formData(
					[
						'grant_type'    => "refresh_token",
						'client_id'     => $this->nibeClientId,
						'client_secret' => $this->nibeClientSecret,
						'refresh_token' => $this->nibeTokenRefresh->value,
					])->send();

					$body = json_decode((string)$response->getBody(), true);

					$this->nibeTokenCurrent->value = $body['access_token'];
					$this->nibeTokenExpiry->value  = $now->addSeconds($body['expires_in'])->format("U");
					$this->nibeTokenRefresh->value = $body['refresh_token'];

					$this->nibeTokenCurrent->save();
					$this->nibeTokenExpiry->save();
					$this->nibeTokenRefresh->save();
				}
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}

		public function getParameterData() : array
		{
			try
			{
				$url = $this->nibeFunctionUrl."/devices/".$this->nibeDeviceId."/points";
				$response = API::get($url)->headers(['Authorization' => "Bearer ".$this->nibeTokenCurrent->value])->send();

				return json_decode((string)$response->getBody(), true);
			}
			catch (Throwable $e)
			{
				throw $e;
			}
		}
	}
