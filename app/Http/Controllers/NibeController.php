<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\EmonAPI;
	use App\APIs\NibeAPI;
	use App\Http\Controllers\EmonController;
	use App\Http\Controllers\WeatherController;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use App\Models\NibeParameter;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Support\Facades\Log;

	class NibeController extends Controller
	{
		protected static $roomTemperature;
		protected static $roomTemperatureForecast;
		protected static $loadCompensationOn;
		protected static $loadCompTempOff;
		protected static $loadCompTempOn;
		protected static $loadCompTempIntermittent;
		protected static $loadCompTempLevel1;

		protected static function initConfig() : void
		{
			static::$loadCompensationOn       ??= config("nibe.loadCompensationOn");
			static::$loadCompTempOff          ??= config("nibe.loadCompTempOff");
			static::$loadCompTempIntermittent ??= config("nibe.loadCompTempIntermittent");
			static::$loadCompTempOn           ??= config("nibe.loadCompTempOn");
			static::$loadCompTempLevel1       ??= config("nibe.loadCompTempLevel1");
		}

		protected static function getRoomTemperature() : ?float
		{
			if (is_null(static::$roomTemperature))
			{
				static::$roomTemperature = EmonController::getLatestRoomTemperatureData();
			}

			return static::$roomTemperature;
		}

		protected static function getRoomTemperatureForecast() : ?array
		{
			if (is_null(static::$roomTemperatureForecast))
			{
				static::$roomTemperatureForecast = EmonController::getForecastRoomTemperatureData();
			}

			return static::$roomTemperatureForecast;
		}

		public static function getNibeData() : void
		{
			try
			{
				static::initConfig();
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

					// populate collection with values for outdoor temp, avg outdoor temp, external flow temp, degree-minutes, calculated flow temp, heating curve, heating offset, min. flow line temp., priority, calculated flow temp cooling, cooling offset
					if (in_array($datum['parameterId'], [40004, 40067, 40071, 40940, 43009, 47007, 47011, 47015, 49994, 44270, 48739]))
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
				// if (true)
				if ($now->format("i") % 5 == 0)
				{
					if (config("nibe.dmOverride") !== false)
					{
						static::dmOverride($dmOverrideCollection);
					}

					if (config("weather.useForecast") !== false)
					{
						WeatherController::syncForecastWithEmon();
					}

					if (static::$loadCompensationOn !== false)
					{
						static::syncRoomTemperatureForecastWithEmon();
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

				if (!$dmOverrideCollection->has("44270"))
				{
					throw new Exception("No data for 'calculated flow temp. cooling'");
				}

				if (!$dmOverrideCollection->has("48739"))
				{
					throw new Exception("No data for 'cooling offset'");
				}

				$outdoorTemp            = $dmOverrideCollection->get("40004");
				$avgOutdoorTemp         = $dmOverrideCollection->get("40067");
				$externalFlowTemp       = $dmOverrideCollection->get("40071");
				$degreeMinutes          = $dmOverrideCollection->get("40940");
				$calculatedFlowTempHtg  = $dmOverrideCollection->get("43009");
				$heatingCurveCurrent    = $dmOverrideCollection->get("47007");
				$heatingOffsetCurrent   = $dmOverrideCollection->get("47011");
				$minFlowLineTempCurrent = $dmOverrideCollection->get("47015");
				$priority               = $dmOverrideCollection->get("49994");
				$calculatedFlowTempCool = $dmOverrideCollection->get("44270");
				$coolingOffsetCurrent   = $dmOverrideCollection->get("48739");

				$htgMode = static::calculateHeatingMode($outdoorTemp, $avgOutdoorTemp);
				// $htgMode = "boost";

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode: '.$htgMode,
				]);

				$dmTarget = static::calculateTargetDm($priority, $htgMode, $outdoorTemp);

				$calculatedFlowTemp = $htgMode == "cooling" ? $calculatedFlowTempCool : $calculatedFlowTempHtg;

				// calculate what the difference should be between ext & calc flow so that we get DM to {$dmTarget} in {minutesToDm} mins
				// each change of offset adjusts the calc flow by approx {offsetFactor}K so we divide by {offsetFactor} at the end and round down to integer
				$offsetChange = round(($externalFlowTemp - (($dmTarget - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor"));

				if ($offsetChange != 0)
				{
					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => '$offsetChange = round(('.$externalFlowTemp.' - (('.$dmTarget.' - '.$degreeMinutes.') / '.config("nibe.minutesToDm").') - '.$calculatedFlowTemp.') / '.config("nibe.offsetFactor").') = round('.($externalFlowTemp - (($dmTarget - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor").') = '.$offsetChange,
					]);
				}

				// if it's warm enough at the daytime peak for $htgMode to be "off" then set $minOffset to -3
				// night-time temperatures may be low enough to need a little heat so that indoor temps don't drop too far
				// ...however if we're in hot water mode then allow lower $minOffset otherwise DegreeMinutes may drop too much
				$minOffset = ($htgMode == "off" && $priority <> 20) ? (config("nibe.cheapMode") !== false ? config("nibe.offsetMinimum") : -3) : config("nibe.offsetMinimum");
				$maxOffset = config("nibe.offsetMaximum");

				if ($htgMode == "intermittent" || config("nibe.cheapMode") !== false)
				{
					// what offset do we need to get the DM to the 'off' value?
					$offsetChangeToOff = round(($externalFlowTemp - ((config("nibe.dmTargetOff") - $degreeMinutes) / config("nibe.minutesToDm")) - $calculatedFlowTemp) / config("nibe.offsetFactor"));
					// Log::info('$offsetChangeToOff: '.$offsetChangeToOff);
					$heatingOffsetNewToOff = min(max($heatingOffsetCurrent + $offsetChangeToOff, $minOffset), $maxOffset);
					// Log::info('$heatingOffsetNewToOff: '.$heatingOffsetNewToOff);

					// what is the maxOffset for current mode?
					$maxOffsetToOff = config("nibe.cheapMode") !== false ? config("nibe.cheapModeOffsetMax") : config("nibe.offsetMaxInt");
					// Log::info('$maxOffsetToOff: '.$maxOffsetToOff);

					if ($heatingOffsetNewToOff > $maxOffsetToOff)
					{
						// to get to 'off' we need a higher maxOffset than the mode value e.g. DM is too high
						// so let's allow a higher maxOffset to reign the DM back in
						$maxOffset = $heatingOffsetNewToOff;
					}
					else
					{
						// otherwise the mode maxOffset is high enough that we could reach the 'off' DM
						// i.e. the upper bound of the offset range is high enough that the compressor will stop but can still come on if need be
						$maxOffset = $maxOffsetToOff;
					}

					// Log::info('$maxOffset: '.$maxOffset);
				}

				$minFlowLineTempMin = 10;

				// if we're on 'intermittent' or 'cheap' mode then set to 10 so that this does not get changed, otherwise 60
				$minFlowLineTempMax = ($htgMode == "intermittent" || config("nibe.cheapMode") !== false) ? 10 : 60;
				$minFlowLineTempNew = $minFlowLineTempCurrent;

				$parameterData = [];

				if ($htgMode != "cooling")
				{
					if ($offsetChange == 0)
					{
						$heatingOffsetNew = min(max($heatingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);

						if ($heatingOffsetNew != $heatingOffsetCurrent)
						{
							$parameterData['47011'] = $heatingOffsetNew;
						}
					}

					if ($offsetChange > 0)
					{
						if ($htgMode == "intermittent" || config("nibe.cheapMode") !== false)
						{
							// if we're already at/above the $maxOffset we don't want to keep the compressor running
							// we also don't want to adjust the min flow line temp
							// on intermittent we're happy for the offset to go down but not for it to go back up if it's already high enough
							if ($heatingOffsetCurrent >= $maxOffset)
							{
								// Log::info("not returning here");
								// return;
							}

							$heatingOffsetNew = min(max($heatingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);

							if ($heatingOffsetNew != $heatingOffsetCurrent)
							{
								$parameterData['47011'] = $heatingOffsetNew;
							}
						}
						else
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

							if ($heatingOffsetNew != $heatingOffsetCurrent)
							{
								$parameterData['47011'] = $heatingOffsetNew;
							}
						}
					}

					// we're in heating mode so let's get the cooling offset back to 0
					$coolingOffsetNew = $coolingOffsetCurrent;

					// nibe cooling mode continues until avg outdoor temp is 1degC below the temperature setpoint
					// so whilst $htgMode is not cooling let's not adjust the cooling offset the avg temp has dropped
					// in case the ashp is still actually running in cooling mode
					if ($avgOutdoorTemp < config("nibe.coolingStartTemp") - 1)
					{
						if ($coolingOffsetCurrent > 0)
						{
							$coolingOffsetNew = $coolingOffsetCurrent - 1;
						}
						elseif ($coolingOffsetCurrent < 0)
						{
							$coolingOffsetNew = $coolingOffsetCurrent + 1;
						}
					}

					if ($coolingOffsetNew != $coolingOffsetCurrent)
					{
						$parameterData['48739'] = $coolingOffsetNew;
					}
				}
				else
				{
					// $htgMode == "cooling"
					$coolingOffsetNew = min(max($coolingOffsetCurrent + $offsetChange, $minOffset), $maxOffset);

					$currentDewpoint = WeatherController::getCurrentDewpoint();

					if ($currentDewpoint != null)
					{
						if ($calculatedFlowTemp < $currentDewpoint)
						{
							$coolingOffsetNew = $coolingOffsetCurrent + 1;
						}
						elseif ($calculatedFlowTemp + 2 * $offsetChange < $currentDewpoint)
						{
							$coolingOffsetNew = $coolingOffsetCurrent;
						}
					}

					if ($coolingOffsetNew != $coolingOffsetCurrent)
					{
						$parameterData['48739'] = $coolingOffsetNew;
					}

					// we're in cooling mode so let's get the heating offset back to 0
					$heatingOffsetNew = $heatingOffsetCurrent;

					// similar to !cooling above, hold off winding the heating offset down until we're 1degC away from the temperature setpoint
					// probably don't need this but nice for the symmetry
					if ($avgOutdoorTemp > config("nibe.coolingStartTemp") + 1)
					{
						if ($heatingOffsetCurrent > 0)
						{
							$heatingOffsetNew = $heatingOffsetCurrent - 1;
						}
						elseif ($heatingOffsetCurrent < 0)
						{
							$heatingOffsetNew = $heatingOffsetCurrent + 1;
						}
					}

					if ($heatingOffsetNew != $heatingOffsetCurrent)
					{
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

		public static function calculateHeatingMode(float $outdoorTemp, float $avgOutdoorTemp) : string
		{
			if ($outdoorTemp < config("nibe.tempFreqMin"))
			{
				// $htgMode = "on";
				$htgMode = "intermittent";
			}
			elseif ($avgOutdoorTemp < config("nibe.dmTargetOffTemp"))
			{
				$htgMode = "intermittent";
			}
			else
			{
				$htgMode = "intermittent";
			}

			if ((config("nibe.allowBoosts") !== false) ? static::isBoostActive($outdoorTemp, $avgOutdoorTemp) : false)
			{
				// Log::info("Boost is active");
				$htgMode = "boost";
			}

			$nextDayHighTemperatureAverage = null;
			$forecastTemperature = null;

			if (config("weather.useForecast") !== false)
			{
				$forecastTemperature = WeatherController::getForecastAverageTemperature();
				$nextDayHighTemperatureAverage = WeatherController::getNextDayHighTemperatures();
			}

			if (!is_null($forecastTemperature) && $forecastTemperature < config("nibe.dmTargetBoostTemp"))
			{
				$htgMode = "boost";

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$forecastTemperature '.$forecastTemperature.' < '.config("nibe.dmTargetBoostTemp").': $htgMode = '.$htgMode,
				]);
			}

			if (static::$loadCompensationOn !== false)
			{
				if (!is_null(static::getRoomTemperature()))
				{
					if (static::getRoomTemperature() > static::$loadCompTempOff)
					{
						$htgMode = "off";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature '.static::getRoomTemperature().' > '.static::$loadCompTempOff.': $htgMode = '.$htgMode,
						]);
					}
					elseif (static::getRoomTemperature() > static::$loadCompTempIntermittent)
					{
						$htgMode = "intermittent";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature '.static::getRoomTemperature().' > '.static::$loadCompTempIntermittent.': $htgMode = '.$htgMode,
						]);
					}
					elseif (static::getRoomTemperature() > static::$loadCompTempLevel1)
					{
						$htgMode = "boost";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature '.static::getRoomTemperature().' > '.static::$loadCompTempLevel1.': $htgMode = '.$htgMode,
						]);
					}
					else
					{
						$htgMode = "extraBoost";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature '.static::getRoomTemperature().' is <= '.static::$loadCompTempLevel1.': $htgMode = '.$htgMode,
						]);
					}
				}

				if (!is_null(static::getRoomTemperatureForecast()))
				{
					if (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempOff)
					{
						$htgMode = "off";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' > '.static::$loadCompTempOff.': $htgMode = '.$htgMode,
						]);
					}
					elseif (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempIntermittent)
					{
						$htgMode = "intermittent";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' > '.static::$loadCompTempIntermittent.': $htgMode = '.$htgMode,
						]);
					}
					elseif (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempOn)
					{
						$htgMode = "on";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' > '.static::$loadCompTempOn.': $htgMode = '.$htgMode,
						]);
					}
					elseif (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempLevel1)
					{
						$htgMode = "boost";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' > '.static::$loadCompTempLevel1.': $htgMode = '.$htgMode,
						]);
					}
					else
					{
						$htgMode = "extraBoost";

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => 'Room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' is <= '.static::$loadCompTempLevel1.': $htgMode = '.$htgMode,
						]);
					}
				}
			}

			if (!is_null($nextDayHighTemperatureAverage) && $nextDayHighTemperatureAverage > config("nibe.runLevel1Temp"))
			{
				// nudge $htgMode down a notch if warmer temps expected later
				if ($htgMode == "extraBoost")
				{
					$htgMode = "boost";
				}
				elseif ($htgMode == "boost")
				{
					$htgMode = "on";
				}
				elseif ($htgMode == "on")
				{
					$htgMode = "intermittent";
				}
				elseif ($htgMode == "intermittent")
				{
					$htgMode = "off";
				}

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$nextDayHighTemperatureAverage '.$nextDayHighTemperatureAverage.' > '.config("nibe.runLevel1Temp").': $htgMode = '.$htgMode,
				]);
			}

			// recent past average temperature is above threshold, or forecast high temperature is a few degrees above threshold [pre-emptive cooling]
			// actually this won't work since the ASHP won't switch to cooling until the first condition is met anyway
			// if ($avgOutdoorTemp > config("nibe.coolingStartTemp") || (!is_null($nextDayHighTemperatureAverage) && $nextDayHighTemperatureAverage > (config("nibe.coolingStartTemp") + 3)))
			if ($avgOutdoorTemp > config("nibe.coolingStartTemp"))
			{
				$htgMode = "cooling";
			}

			return $htgMode;
		}

		public static function isBoostActive(float $outdoorTemp, float $avgOutdoorTemp) : bool
		{
			// return true;
			$now = CarbonImmutable::now();

			try
			{
				$scheduleString = Setting::firstWhere("key", "agile_schedule")->value;
				$schedules = json_decode($scheduleString, true);

				if (config("weather.useForecast") !== false)
				{
					$forecastTemperature = WeatherController::getForecastAverageTemperature();

					if (is_null($forecastTemperature))
					{
						throw new Exception('$forecastTemperature is null');
					}

					$scheduleWindow = "";

					if ($outdoorTemp < config("nibe.tempFreqMin") || $forecastTemperature < config("nibe.tempFreqMin"))
					{
						$scheduleWindow = "constant";
					}
					elseif ($outdoorTemp < config("nibe.runLevel2Temp") || $forecastTemperature < config("nibe.runLevel2Temp"))
					{
						$scheduleWindow = "cosy";
					}
					elseif ($outdoorTemp < config("nibe.runLevel1Temp") || $forecastTemperature < config("nibe.runLevel1Temp"))
					{
						$scheduleWindow = "cosy";
					}
					else
					{
						// if not cold at all then we're not boosting
						return false;
					}

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => '$scheduleWindow: '.$scheduleWindow,
					]);

					// it might be cold/cool now or in the short-term forecast, but check the upcoming temperature peaks
					$nextDayHighTemperatureAverage = WeatherController::getNextDayHighTemperatures();

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => '$nextDayHighTemperatureAverage: '.$nextDayHighTemperatureAverage,
					]);

					// if it's going to be warm enough at some point then override the schedule window to just boost at the cheapest times or not at all
					if (!is_null($nextDayHighTemperatureAverage))
					{
						if ($nextDayHighTemperatureAverage > config("nibe.runLevel1Temp"))
						{
							return false;
						}

						if ($nextDayHighTemperatureAverage > config("nibe.runLevel2Temp"))
						{
							$scheduleWindow = "cosy";
						}
					}

					if ($scheduleWindow == "constant")
					{
						// this key does not exist in $schedules so return here before we try accessing it in the foreach loop below
						return true;
					}

					foreach ($schedules[$scheduleWindow] as $schedule)
					{
						$start = CarbonImmutable::parse($schedule['start']);
						$end = CarbonImmutable::parse($schedule['end']);

						if ($now->isAfter($start) && $now->isBefore($end))
						{
							return true;
						}
					}

					return false;
				}
				else
				{
					if ($avgOutdoorTemp >= config("nibe.runLevel1Temp"))
					{
						if ($outdoorTemp >= config("nibe.dmTargetOffTemp"))
						{
							return false;
						}

						foreach ($schedules['cheapest_3_hours'] as $schedule)
						{
							$start = CarbonImmutable::parse($schedule['start']);
							$end = CarbonImmutable::parse($schedule['end']);

							if ($now->isAfter($start) && $now->isBefore($end))
							{
								return true;
							}
						}

						return false;
					}

					if ($avgOutdoorTemp >= config("nibe.runLevel2Temp"))
					{
						if ($outdoorTemp >= config("nibe.dmTargetOffTemp"))
						{
							return false;
						}

						if ($outdoorTemp >= config("nibe.runLevel1Temp"))
						{
							foreach ($schedules['cheapest_3_hours'] as $schedule)
							{
								$start = CarbonImmutable::parse($schedule['start']);
								$end = CarbonImmutable::parse($schedule['end']);

								if ($now->isAfter($start) && $now->isBefore($end))
								{
									return true;
								}
							}

							return false;
						}

						foreach ($schedules['cheapest_6_hours'] as $schedule)
						{
							$start = CarbonImmutable::parse($schedule['start']);
							$end = CarbonImmutable::parse($schedule['end']);

							if ($now->isAfter($start) && $now->isBefore($end))
							{
								return true;
							}
						}

						return false;
					}

					if ($avgOutdoorTemp >= config("nibe.tempFreqMin"))
					{
						if ($outdoorTemp >= config("nibe.dmTargetOffTemp"))
						{
							return false;
						}

						if ($outdoorTemp >= config("nibe.runLevel1Temp"))
						{
							foreach ($schedules['cheapest_3_hours'] as $schedule)
							{
								$start = CarbonImmutable::parse($schedule['start']);
								$end = CarbonImmutable::parse($schedule['end']);

								if ($now->isAfter($start) && $now->isBefore($end))
								{
									return true;
								}
							}

							return false;
						}

						if ($outdoorTemp >= config("nibe.runLevel2Temp"))
						{
							foreach ($schedules['cheapest_6_hours'] as $schedule)
							{
								$start = CarbonImmutable::parse($schedule['start']);
								$end = CarbonImmutable::parse($schedule['end']);

								if ($now->isAfter($start) && $now->isBefore($end))
								{
									return true;
								}
							}

							return false;
						}

						return true;
					}

					return true;
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

			return false;
		}

		public static function calculateTargetDm(int $priority, string $htgMode, float $outdoorTemp) : int
		{
			if ($htgMode == "off")
			{
				$dmTarget = config("nibe.dmTargetOff");

				if ($priority == 20)
				{
					$dmTarget = $dmTarget - 60;
				}

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode is "'.$htgMode.'" and $priority is "'.$priority.'": $dmTarget = '.$dmTarget,
				]);

				return $dmTarget;
			}

			if ($htgMode == "intermittent")
			{
				$dmTarget = config("nibe.dmTarget");

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode is "'.$htgMode.'": $dmTarget = '.$dmTarget,
				]);

				return $dmTarget;
			}

			if ($htgMode == "on")
			{
				$dmTarget = config("nibe.dmTarget");

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode is "'.$htgMode.'": $dmTarget = '.$dmTarget,
				]);

				return $dmTarget;
			}

			if ($htgMode == "boost")
			{
				$dmTarget = config("nibe.dmTargetBoost");

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode is "'.$htgMode.'": $dmTarget = '.$dmTarget,
				]);

				return $dmTarget;
			}

			if ($htgMode == "extraBoost")
			{
				$dmTarget = config("nibe.dmTarget") + config("nibe.dmTargetBoost"); // room temperature/forecast very low - give it all the beans!

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode is "'.$htgMode.'": $dmTarget = '.$dmTarget,
				]);

				return $dmTarget;
			}

			// if (static::$loadCompensationOn !== false)
			// {
			// 	if (!is_null(static::getRoomTemperature()))
			// 	{
			// 		if (static::getRoomTemperature() > static::$loadCompTempOff)
			// 		{
			// 			// $htgMode == "off" - we shouldn't get here
			// 			// $dmTarget = config("nibe.dmTargetOff");
			// 		}
			// 		elseif (static::getRoomTemperature() > static::$loadCompTempIntermittent)
			// 		{
			// 			// $htgMode = "intermittent"
			// 			// if $dmTarget hasn't been changed to dmTargetBoost it will be dmTarget so we don't need to set it again
			// 			// if $dmTarget has been changed to dmTargetBoost then we probs don't want to change it back again
			// 			// $dmTarget = config("nibe.dmTarget");
			// 		}
			// 		elseif (static::getRoomTemperature() > static::$loadCompTempLevel1)
			// 		{
			// 			$dmTarget = config("nibe.dmTargetBoost"); // $htgMode = "boost"

			// 			ActivityLog::create(
			// 			[
			// 				'controller' => __CLASS__,
			// 				'method'     => __FUNCTION__,
			// 				'level'      => "info",
			// 				'message'    => '$htgMode is "'.$htgMode.'" and room temperature '.static::getRoomTemperature().' > '.static::$loadCompTempLevel1.': $dmTarget = '.$dmTarget,
			// 			]);
			// 		}
			// 		else
			// 		{
			// 			$dmTarget = config("nibe.dmTarget") + config("nibe.dmTargetBoost"); // $htgMode = "boost" and room temperature very low - give it all the beans!

			// 			ActivityLog::create(
			// 			[
			// 				'controller' => __CLASS__,
			// 				'method'     => __FUNCTION__,
			// 				'level'      => "info",
			// 				'message'    => '$htgMode is "'.$htgMode.'" and room temperature '.static::getRoomTemperature().' <= '.static::$loadCompTempLevel1.': $dmTarget = '.$dmTarget,
			// 			]);
			// 		}
			// 	}

			// 	if (!is_null(static::getRoomTemperatureForecast()['tempForecast']))
			// 	{
			// 		if (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempOff)
			// 		{
			// 			// $htgMode == "off" - we shouldn't get here
			// 			// $dmTarget = config("nibe.dmTargetOff");
			// 		}
			// 		elseif (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempIntermittent)
			// 		{
			// 			// $htgMode = "intermittent"
			// 			// if $dmTarget hasn't been changed to dmTargetBoost it will be dmTarget so we don't need to set it again
			// 			// if $dmTarget has been changed to dmTargetBoost then we probs don't want to change it back again
			// 			// $dmTarget = config("nibe.dmTarget");
			// 		}
			// 		elseif (static::getRoomTemperatureForecast()['tempForecast'] > static::$loadCompTempLevel1)
			// 		{
			// 			$dmTarget = config("nibe.dmTargetBoost"); // $htgMode = "boost"

			// 			ActivityLog::create(
			// 			[
			// 				'controller' => __CLASS__,
			// 				'method'     => __FUNCTION__,
			// 				'level'      => "info",
			// 				'message'    => '$htgMode is "'.$htgMode.'" and room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' > '.static::$loadCompTempLevel1.': $dmTarget = '.$dmTarget,
			// 			]);
			// 		}
			// 		else
			// 		{
			// 			$dmTarget = config("nibe.dmTarget") + config("nibe.dmTargetBoost"); // $htgMode = "boost" and room temperature forecast very low - give it all the beans!

			// 			ActivityLog::create(
			// 			[
			// 				'controller' => __CLASS__,
			// 				'method'     => __FUNCTION__,
			// 				'level'      => "info",
			// 				'message'    => '$htgMode is "'.$htgMode.'" and room temperature forecast '.static::getRoomTemperatureForecast()['tempForecast'].' <= '.static::$loadCompTempLevel1.': $dmTarget = '.$dmTarget,
			// 			]);
			// 		}
			// 	}
			// }

			if ($htgMode == "cooling")
			{
				$dmTarget = config("nibe.dmTargetCooling");

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => '$htgMode is "'.$htgMode.'": $dmTarget = '.$dmTarget,
				]);

				return $dmTarget;
			}

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "warning",
				'message'    => '$htgMode is "'.$htgMode.'": $dmTarget = 0',
			]);

			return 0;
		}

		public static function syncRoomTemperatureForecastWithEmon() : void
		{
			try
			{
				$syncSuccess = EmonAPI::postInputData("local", static::getRoomTemperatureForecast()['tempCurrentTimestamp'], "emonth2_23", json_encode(["temperature forecast" => static::getRoomTemperatureForecast()['tempForecast']]));

				if (!$syncSuccess)
				{
					throw new Exception("Unable to sync room temperature forecast with emon");
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
