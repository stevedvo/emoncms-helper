<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\APIs\EmonAPI;
	use App\Models\EmonFeed;
	use Illuminate\Support\Facades\Log;

	class EmonController extends Controller
	{
		public static function SyncEmonFeeds()
		{
			try
			{
				$feeds = EmonAPI::getFeedList("local");

				if (!is_array($feeds))
				{
					Log::error(__CLASS__.'->'.__FUNCTION__.'(): $feeds is not an array');
					return;
				}

				foreach ($feeds as $feed)
				{
					$emonFeed = new EmonFeed;
					$emonFeed->fill($feed);
					Log::info(__CLASS__."->".__FUNCTION__."(): ".$emonFeed);
				}
			}
			catch (Throwable $e)
			{
				Log::error(__CLASS__."->".__FUNCTION__."(): ".$e);
			}
		}
	}
