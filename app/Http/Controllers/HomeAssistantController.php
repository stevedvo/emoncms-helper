<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\HomeAssistantAPI;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
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

				$homeAssistant = new HomeAssistantAPI();

				// if priority is heating [30] or cooling [60]
				$homeAssistant->adjustHiveThermostat(($latestPriorityNibeFeedItem->rawValue == 30 || $latestPriorityNibeFeedItem->rawValue == 60) ? config("hive.targetOnTemp") : config("hive.targetOffTemp"));
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
