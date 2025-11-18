<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\HomeAssistantAPI;
	use App\Http\Controllers\EmonController;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use App\Models\Setting;
	use Illuminate\Support\Facades\Log;

	class HomeAssistantController extends Controller
	{
		public static function adjustHiveThermostat() : void
		{
			try
			{
				$latestPriorityNibeFeedItem = NibeFeedItem::where('parameterId', "49994")->orderBy('id', "desc")->first();

				if (!($latestPriorityNibeFeedItem instanceof NibeFeedItem))
				{
					throw new Exception("NibeFeedItem not found");
				}

				$priority = (int)$latestPriorityNibeFeedItem->rawValue;
				$setting = Setting::firstWhere(["key" => "htgMode"]);
				$htgMode = $setting?->value;

				$targetTemp = config("hive.targetOffTemp");

				// if priority is either heating [30] or cooling [60]
				if ($priority === 30 || $priority === 60)
				{
					$targetTemp = config("hive.targetOnTemp");

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => "Priority is '$priority'; targetTemp is ".$targetTemp."degC",
					]);

					if ($htgMode == "extraBoost")
					{
						$targetTemp = config("hive.targetOffTemp");

						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "info",
							'message'    => "htgMode is '$htgMode'; targetTemp is ".$targetTemp."degC",
						]);

						$flowTempRaw = EmonController::getLatestEmonData("heatmeter_FlowT", "local", 5);
						$flowTemp = (is_null($flowTempRaw) || $flowTempRaw === "") ? 0.0 : (float)$flowTempRaw;

						if ($flowTemp === 0.0)
						{
							// Log a warning, but continue safely
							ActivityLog::create(
							[
								'controller' => __CLASS__,
								'method'     => __FUNCTION__,
								'level'      => "warning",
								'message'    => "Flow temperature missing — defaulting to $flowTemp",
							]);
						}

						if ($flowTemp > 45)
						{
							$targetTemp = config("hive.targetOnTemp");

							ActivityLog::create(
							[
								'controller' => __CLASS__,
								'method'     => __FUNCTION__,
								'level'      => "info",
								'message'    => "flowTemp is $flowTemp; targetTemp is ".$targetTemp."degC",
							]);
						}
					}
				}

				$homeAssistant = new HomeAssistantAPI();

				$homeAssistant->adjustHiveThermostat($targetTemp);
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
