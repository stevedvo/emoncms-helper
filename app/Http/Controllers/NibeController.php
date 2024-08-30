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
		public static function getNibeData() : void
		{
			try
			{
				$now = CarbonImmutable::now();
				$nibe = new NibeAPI();

				$parameterData = collect($nibe->getParameterData())->unique(function(array $item)
				{
					return $item['parameterId'].$item['timestamp'].$item['value'];
				});

				// Log::info($parameterData);

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

					// populate collection with values for outdoor temp, avg outdoor temp, external flow temp, degree-minutes, calculated flow temp, heating curve, heating offset, min. flow line temp., and priority
					if (in_array($datum['parameterId'], [40004, 40067, 40071, 40940, 43009, 47007, 47011, 47015, 49994]))
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
					static::syncNibeData("local", $emonPostCollection->all());

					// Log::info('$emonPostCollection: '.$emonPostCollection);

					// $emonRemotePostCollection = $emonPostCollection->only(['calculated flow temp.']);
					$emonRemotePostCollection = new Collection();

					if ($emonPostCollection->has('calculated flow temp.'))
					{
						$emonRemotePostCollection->put('calculated flow temp.', $emonPostCollection->get('calculated flow temp.'));
					}

					// Log::info('$emonRemotePostCollection: '.$emonRemotePostCollection);

					if (!$emonRemotePostCollection->isEmpty())
					{
						static::syncNibeData("remote", $emonRemotePostCollection->all());
					}
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

		public static function syncNibeData(string $environment, array $emonPostArray) : void
		{
			$syncSuccess = false;

			foreach ($emonPostArray as $title => $nibeFeedItem)
			{
				try
				{
					if ($title != "priority")
					{
						$syncSuccess = EmonAPI::postInputData($environment, $nibeFeedItem->timestamp, "nibe", json_encode([$title => $nibeFeedItem->rawValue]));
					}
					else
					{
						$priorities =
						[
							'hot water' => 0,
							'heating'   => 0,
							'cooling'   => 0,
						];

						switch ($nibeFeedItem->rawValue)
						{
							case 20:
							{
								$priorities['hot water'] = 1;
							}
							break;

							case 30:
							{
								$priorities['heating'] = 1;
							}
							break;

							case 60:
							{
								$priorities['cooling'] = 1;
							}
							break;

							default:
							break;
						}

						foreach ($priorities as $priority => $value)
						{
							$syncSuccess = EmonAPI::postInputData($environment, $nibeFeedItem->timestamp, "nibe", json_encode([$priority => $value]));
						}
					}
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

				if ($environment == "local")
				{
					$nibeFeedItem->syncAttempts++;
					$nibeFeedItem->syncStatus = $syncSuccess ? "success" : "failed";
					$nibeFeedItem->save();
				}
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

				if (!$dmOverrideCollection->has("47007"))
				{
					throw new Exception("No data for 'heating curve'");
				}

				if (!$dmOverrideCollection->has("47011"))
				{
					throw new Exception("No data for 'heating offset'");
				}

				if (!$dmOverrideCollection->has("47015"))
				{
					throw new Exception("No data for 'min. flow line temp.'");
				}

				if (!$dmOverrideCollection->has("49994"))
				{
					throw new Exception("No data for 'priority'");
				}

				$outdoorTemp            = $dmOverrideCollection->get("40004");
				$avgOutdoorTemp         = $dmOverrideCollection->get("40067");
				$externalFlowTemp       = $dmOverrideCollection->get("40071");
				$degreeMinutes          = $dmOverrideCollection->get("40940");
				$calculatedFlowTemp     = $dmOverrideCollection->get("43009");
				$heatingCurveCurrent    = $dmOverrideCollection->get("47007");
				$heatingOffsetCurrent   = $dmOverrideCollection->get("47011");
				$minFlowLineTempCurrent = $dmOverrideCollection->get("47015");
				$priority               = $dmOverrideCollection->get("49994");

				$dmTarget = $avgOutdoorTemp < config("nibe.dmTargetOffTemp") ? config("nibe.dmTarget") : config("nibe.dmTargetOff");

				if ($priority == 20)
				{
					$dmTarget = $dmTarget - 90;
				}

				// calculate what the difference should be between ext & calc flow so that we get DM to {$dmTarget} in {minutesToDm} mins
				// each change of offset adjusts the calc flow by approx {offsetFactor}K so we divide by {offsetFactor} at the end and round down to integer
				$offsetChange = round(($externalFlowTemp - (($dmTarget - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor"));
				// Log::info('$offsetChange = round(('.$externalFlowTemp.' - (('.$dmTarget.' - '.$degreeMinutes.') / '.config("nibe.minutesToDm").') - '.$calculatedFlowTemp.') / '.config("nibe.offsetFactor").') = round('.($externalFlowTemp - (($dmTarget - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor").') = '.$offsetChange);

				// constrain the offset within a smaller range to hopefully avoid massive swings
				// try to avoid compressor inadvertently either kicking in to a higher output [DM too negative] or switching off [DM >= 0]
				$minOffset = config("nibe.offsetMinimum");
				$maxOffset = ($avgOutdoorTemp < config("nibe.dmTargetOffTemp") && $outdoorTemp > config("nibe.tempFreqMin")) ? 0 : config("nibe.offsetMaximum");

				$minFlowLineTempMin = 10;
				$minFlowLineTempMax = 60;
				$minFlowLineTempNew = $minFlowLineTempCurrent;

				$minHeatingCurve = 0;
				$maxHeatingCurve = 15;
				$heatingCurveNew = $heatingCurveCurrent;

				// if we want to increase the offset but we're already at max then adjust the heating curve instead
				// if we want to decrease the offset but the curve is higher than minimum then adjust the curve back down instead
				// if ($offsetChange > 0 && $heatingOffsetCurrent == $maxOffset && $heatingCurveCurrent < $maxHeatingCurve)
				// {
				// 	$heatingCurveNew = min($heatingCurveCurrent + $offsetChange, $maxHeatingCurve);
				// }
				// elseif ($offsetChange < 0 && $heatingCurveCurrent > $minHeatingCurve)
				// {
				// 	$heatingCurveNew = $heatingCurveCurrent - 1;
				// }

				$parameterData = [];

				if ($offsetChange == 0)
				{
					return;
				}
				elseif ($offsetChange > 0)
				{
					if ($heatingOffsetCurrent != $maxOffset)
					{
						$heatingOffsetNew = min(max($heatingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);
						$parameterData['47011'] = $heatingOffsetNew;

						// if this puts the heating offset up to max then prep the min. flow line temp. to be equal to the current calculated flow temperature
						if ($heatingOffsetNew == $maxOffset)
						{
							$parameterData['47015'] = round($calculatedFlowTemp);
						}
					}
					else
					{
						// heating offset is maxed out so let's increase the min. flow line temp. instead if we need to
						$minFlowLineTempNew = min($minFlowLineTempCurrent + ($offsetChange * config("nibe.offsetFactor")), $minFlowLineTempMax);

						if ($minFlowLineTempNew == $minFlowLineTempCurrent)
						{
							return;
						}

						$parameterData['47015'] = round($minFlowLineTempNew);
					}
				}
				elseif ($offsetChange < 0)
				{
					// we need to decrease the calculated temp, first check if we have set min. flow line temp. above the minimum level
					if ($minFlowLineTempCurrent != $minFlowLineTempMin)
					{
						if ($minFlowLineTempCurrent >= $calculatedFlowTemp)
						{
							// calculated flow temp is being determined by min. flow line temp. so let's drop it a bit
							$minFlowLineTempNew = max($minFlowLineTempCurrent + ($offsetChange * config("nibe.offsetFactor")), $minFlowLineTempMin);

							if ($minFlowLineTempNew == $minFlowLineTempCurrent)
							{
								return;
							}

							$parameterData['47015'] = round($minFlowLineTempNew);
						}
						else
						{
							// dropping a bit won't make a difference since the calculated flow temperature is being determined by the heating offset
							// so change min. flow line temp. back down to minimum level and adjust the heating offset down
							$minFlowLineTempNew = $minFlowLineTempMin;
							$parameterData['47015'] = round($minFlowLineTempNew);

							$heatingOffsetNew = min(max($heatingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);
							$parameterData['47011'] = $heatingOffsetNew;
						}
					}
					else
					{
						$heatingOffsetNew = min(max($heatingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);
						$parameterData['47011'] = $heatingOffsetNew;
					}
				}

				if (count($parameterData) == 0)
				{
					return;
				}

				$nibe = new NibeAPI();
				$response = $nibe->setParameterData($parameterData);

				$errors = [];

				foreach ($parameterData as $parameterId => $value)
				{
					if (!isset($response[$parameterId]))
					{
						$errors[] = "No response for parameter #".$parameterId;
					}
					elseif ($response[$parameterId] != "modified")
					{
						$errors[] = "Parameter #".$parameterId." not modified";
					}
					elseif ($response[$parameterId] == "modified")
					{
						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => "Parameter #".$parameterId." successfully modified",
						]);
					}
				}

				if (count($errors) > 0)
				{
					throw new Exception("Error(s) with request: ".implode("; ", $errors));
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
			$now = CarbonImmutable::now();
			$emonPostCollection = new Collection();
			$latestPriorityNibeFeedItem = NibeFeedItem::where('parameterId', "49994")->orderBy('id', "desc")->first();

			if ($latestPriorityNibeFeedItem instanceof NibeFeedItem)
			{
				// if emon already updated with the 'off' status we don't need to keep updating it
				// it's only the heating & dhw values that we want to keep refreshing
				if ($latestPriorityNibeFeedItem->rawValue == 10 && $latestPriorityNibeFeedItem->syncStatus == "success")
				{
					// return;
				}

				// update timestamp for the emon feed
				$latestPriorityNibeFeedItem->timestamp = $now->setTimezone("UTC")->format("U");
				$emonPostCollection->put('priority', $latestPriorityNibeFeedItem);

				try
				{
					static::syncNibeData("local", $emonPostCollection->all());
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

				// update timestamp for the emon feed again since syncNibeData will reset it
				$latestPriorityNibeFeedItem->timestamp = $now->setTimezone("UTC")->format("U");

				try
				{
					static::syncNibeData("remote", $emonPostCollection->all());
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
			else
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => "NibeFeedItem not found",
				]);
			}
		}
	}
