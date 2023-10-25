<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\APIs\NibeAPI;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use Carbon\CarbonImmutable;
	 
	class NibeController extends Controller
	{
		public static function GetNibeData() : void
		{
			try
			{
				$now = CarbonImmutable::now();

				$api = new NibeAPI();
				$parameterData = $api->getParameters();

				foreach ($parameterData as $datum)
				{
					NibeFeedItem::create(
					[
						'parameterId'  => $datum['parameterId'],
						'timestamp'    => $now->format("U"),
						'rawValue'     => $datum['rawValue'],
						'syncAttempts' => 0,
						'syncStatus'   => "pending",
					]);
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
