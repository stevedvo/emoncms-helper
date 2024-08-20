<?php
	namespace App\Console\Commands;

	use App\Http\Controllers\EmonController;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;

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
			try
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "Command Start.",
				]);

				EmonController::syncEmonFeeds();

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "Command End.",
				]);
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
