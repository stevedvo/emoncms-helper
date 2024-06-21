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
								'timestamp'    => CarbonImmutable::parse($datum['timestamp'])->setTimezone("UTC")->format("U"),
							],
							[
								'rawValue'     => $datum['value'],
								'syncAttempts' => 0,
								'syncStatus'   => "pending",
							]);

							if ($nibeFeedItem->wasRecentlyCreated || $nibeFeedItem->syncStatus != "success")
							{
								// exclude "priority" since we are sending this in static::priorityHeartbeat()
								if ($nibeParameters->get($nibeFeedItem->parameterId)->title != "priority")
								{
									$emonPostCollection->put($nibeParameters->get($nibeFeedItem->parameterId)->title, $nibeFeedItem);
								}
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

					// populate collection with values for outdoor temp, avg outdoor temp, external flow temp, degree-minutes, calculated flow temp, heating offset, and priority
					if (in_array($datum['parameterId'], [40004, 40067, 40071, 40940, 43009, 47011, 49994]))
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
				}
				else
				{
					static::syncNibeData($emonPostCollection->all());
				}

				// do this when the minute number is a multiple of 5
				if (config("nibe.dmOverride") !== false && $now->format("i") % 5 == 0)
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

				if ($nibeFeedItem->isDirty())
				{
					// the only time the NibeFeedItem will be dirty is if it is a 'priority' update and we have adjusted the timestamp
					// but we need to retain the original timestamp because we use it to help determine whether or not the data from MyUplink is new
					$nibeFeedItem->discardChanges();
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
				if (!$dmOverrideCollection->has("40004"))
				{
					throw new Exception("No data for 'outdoor temp.'");
				}

				if (!$dmOverrideCollection->has("40067"))
				{
					throw new Exception("No data for 'avg. outdoor temp.'");
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

				if (!$dmOverrideCollection->has("49994"))
				{
					throw new Exception("No data for 'priority'");
				}

				$outdoorTemp          = $dmOverrideCollection->get("40004");
				$avgOutdoorTemp       = $dmOverrideCollection->get("40067");
				$externalFlowTemp     = $dmOverrideCollection->get("40071");
				$degreeMinutes        = $dmOverrideCollection->get("40940");
				$calculatedFlowTemp   = $dmOverrideCollection->get("43009");
				$heatingOffsetCurrent = $dmOverrideCollection->get("47011");
				$priority             = $dmOverrideCollection->get("49994");

				$dmTarget = $avgOutdoorTemp < 12 ? config("nibe.dmTarget") : config("nibe.dmTargetOff");

				if ($priority == 20)
				{
					$dmTarget = $dmTarget - 90;
				}

				// calculate what the difference should be between ext & calc flow so that we get DM to -240 in 15 mins
				// each change of offset adjusts the calc flow by approx 2K so we divide by 2 at the end and round down to integer
				$offsetChange = round(($externalFlowTemp - (($dmTarget - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor"));
				// Log::info('$offsetChange = round(('.$externalFlowTemp.' - (('.$dmTarget.' - '.$degreeMinutes.') / '.config("nibe.minutesToDm").') - '.$calculatedFlowTemp.') / '.config("nibe.offsetFactor").') = round('.($externalFlowTemp - (($dmTarget - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor").') = '.$offsetChange);

				// constrain the offset within a smaller range to hopefully avoid massive swings
				// try to avoid compressor inadvertently either kicking in to a higher output [DM too negative] or switching off [DM >= 0]
				$minOffset = config("nibe.offsetMinimum");
				$maxOffset = ($avgOutdoorTemp < 12 && $outdoorTemp > config("nibe.tempFreqMin")) ? 0 : config("nibe.offsetMaximum");

				$heatingOffsetNew = min(max($heatingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);
				// Log::info('$heatingOffsetNew = '.$heatingOffsetNew);

				if ($heatingOffsetNew == $heatingOffsetCurrent)
				{
					return;
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

		public static function priorityHeartbeat() : void
		{
			try
			{
				$now = CarbonImmutable::now();
				$emonPostCollection = new Collection();
				$latestPriorityNibeFeedItem = NibeFeedItem::where('parameterId', "49994")->orderBy('id', "desc")->first();

				if ($latestPriorityNibeFeedItem instanceof NibeFeedItem)
				{
					// if emon already updated with the 'off' status we don't need to keep updating it
					// it's only the heating & dhw values that we want to keep refreshing
					if ($latestPriorityNibeFeedItem->rawValue == 10 && $latestPriorityNibeFeedItem->syncStatus == "success")
					{
						return;
					}

					// update timestamp for the emon feed
					$latestPriorityNibeFeedItem->timestamp = $now->setTimezone("UTC")->format("U");
					$emonPostCollection->put('priority', $latestPriorityNibeFeedItem);

					static::syncNibeData($emonPostCollection->all());
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
