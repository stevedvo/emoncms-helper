<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\EmonAPI;
	use App\APIs\NibeAPI;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use App\Models\NibeParameter;
	use Carbon\CarbonImmutable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Support\Facades\Log;

	class NibeController extends Controller
	{
		public static function GetNibeData() : void
		{
			try
			{
				$now = CarbonImmutable::now();
				$api = new NibeAPI();

				$parameterData = collect($api->getParameterData())->unique(function(array $item)
				{
					return $item['parameterId'].$item['timestamp'].$item['value'];
				});

				$nibeParameters = NibeParameter::all()->keyBy("parameterId");

				$emonPostCollection = new Collection();
				$dmOverrideCollection = new Collection();

				$parameterData->each(function(array $datum, int $key) use ($nibeParameters, $emonPostCollection, $dmOverrideCollection)
				{
					if ($nibeParameters->has($datum['parameterId']))
					{
						try
						{
							$nibeFeedItem = NibeFeedItem::firstOrCreate(
							[
								'parameterId'  => $datum['parameterId'],
								'timestamp'    => CarbonImmutable::parse($datum['timestamp'])->setTimezone("UTC")->format("U")
							],
							[
								'rawValue'     => $datum['value'],
								'syncAttempts' => 0,
								'syncStatus'   => "pending",
							]);

							if ($nibeFeedItem->wasRecentlyCreated || $nibeFeedItem->syncStatus != "success")
							{
								$emonPostCollection->put($nibeParameters->get($nibeFeedItem->parameterId)->title, $nibeFeedItem);
							}
						}
						catch (Throwable $e)
						{
							ActivityLog::create(
							[
								'controller' => __CLASS__,
								'method'     => __FUNCTION__,
								'level'      => "error",
								'message'    => $e->getMessage(),
							]);
						}
					}

					// populate collection with values for outdoor temp, external flow temp, degreem-minutes, calculated flow temp, and heating offset
					if (in_array($datum['parameterId'], [40004, 40071, 40940, 43009, 47011]))
					{
						$dmOverrideCollection->put($datum['parameterId'], $datum['value']);
					}
				});

				if ($emonPostCollection->isEmpty())
				{
					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => "No new NIBE data found",
					]);

					return;
				}

				static::syncNibeData($emonPostCollection->all());

				if (in_array($now->format("i"), ["00", "15", "30", "45"]))
				{
					static::dmOverride($dmOverrideCollection);
				}
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}
		}

		public static function syncNibeData(array $emonPostArray) : void
		{
			$syncSuccess = false;

			foreach ($emonPostArray as $title => $nibeFeedItem)
			{
				try
				{
					$syncSuccess = EmonAPI::postInputData("local", $nibeFeedItem->timestamp, "nibe", json_encode([$title => $nibeFeedItem->rawValue]));
				}
				catch (Throwable $e)
				{
					$syncSuccess = false;

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "error",
						'message'    => $e->getMessage(),
					]);
				}

				$nibeFeedItem->syncAttempts++;
				$nibeFeedItem->syncStatus = $syncSuccess ? "success" : "failed";
				$nibeFeedItem->save();
			}
		}

		public static function dmOverride(Collection $dmOverrideCollection) : void
		{
			try
			{
				if (config("nibe.dmOverride") === false)
				{
					return;
				}

				if (!$dmOverrideCollection->has("40004"))
				{
					throw new Exception("No data for 'outdoor temp.'");
				}

				if (!$dmOverrideCollection->has("40071"))
				{
					throw new Exception("No data for 'external flow temp.'");
				}

				if (!$dmOverrideCollection->has("40940"))
				{
					throw new Exception("No data for 'degree minutes'");
				}

				if (!$dmOverrideCollection->has("43009"))
				{
					throw new Exception("No data for 'calculated flow temp.'");
				}

				if (!$dmOverrideCollection->has("47011"))
				{
					throw new Exception("No data for 'heating offset'");
				}

				// get the most recent value for the outside temperature
				$outdoorTemp = $dmOverrideCollection->get("40004");
				$externalFlowTemp = $dmOverrideCollection->get("40071");
				$degreeMinutes = $dmOverrideCollection->get("40940");
				$calculatedFlowTemp = $dmOverrideCollection->get("43009");
				$heatingOffsetCurrent = $dmOverrideCollection->get("47011");
				$heatingOffsetNew = $heatingOffsetCurrent;

				if ($degreeMinutes == config("nibe.dmTarget"))
				{
					return;
				}
				elseif ($degreeMinutes < config("nibe.dmTarget"))
				{
					// degree minutes too negative, decrease the calculated flow temp

					if ($heatingOffsetCurrent == -10)
					{
						return;
					}

					$heatingOffsetNew = $heatingOffsetCurrent - 1;
				}
				elseif ($degreeMinutes > config("nibe.dmTarget"))
				{
					// degree minutes not negative enough

					if ($externalFlowTemp < $calculatedFlowTemp)
					{
						// degree minutes is already becoming more negative
						return;
					}

					if ($heatingOffsetCurrent == 10)
					{
						return;
					}

					if ($outdoorTemp > config("nibe.tempFreqMin"))
					{
						if ($heatingOffsetCurrent == 0)
						{
							// we do not need to increase
							return;
						}
						elseif ($heatingOffsetCurrent > 0)
						{
							if ($degreeMinutes > -15)
							{
								// allow cycle to pretty much complete then reset
								$heatingOffsetNew = 0;
							}
						}
						elseif ($heatingOffsetCurrent < 0)
						{
							$heatingOffsetNew = $heatingOffsetCurrent + 1;
						}
					}
					else
					{
						$heatingOffsetNew = $heatingOffsetCurrent + 1;
					}
				}

				$parameterData = ['47011' => $heatingOffsetNew];

				$api = new NibeAPI();
				$response = $api->setParameterData($parameterData);

				if (!isset($response['status']))
				{
					throw new Exception("Error with request - no status returned");
				}

				if ($response['status'] != 200)
				{
					throw new Exception("Error with request - status: ".$response['status']);
				}
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}
		}
	}
