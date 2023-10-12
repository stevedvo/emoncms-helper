<?php
	namespace App\Console\Commands;

	use App\Http\Controllers\EmonController;
	use Illuminate\Console\Command;
	use Illuminate\Support\Facades\Log;

	class SyncEmonFeeds extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "emon:sync";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Compare feeds from emonCMS and emonHP.local and sync missing data";

		/**
		 * Execute the console command.
		 *
		 * @return void
		 */
		public function handle() : void
		{
			Log::info(__CLASS__."->".__FUNCTION__."(): command start");
			EmonController::SyncEmonFeeds();
			Log::info(__CLASS__."->".__FUNCTION__."(): command end");
		}
	}
