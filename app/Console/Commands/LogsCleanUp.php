<?php
	namespace App\Console\Commands;

	use Throwable;
	use App\Http\Controllers\LogController;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;

	class LogsCleanUp extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "logs:cleanUp";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Clean up old log entries";

		/**
		 * Execute the console command.
		 *
		 * @return void
		 */
		public function handle() : void
		{
			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => "Command Start.",
			]);

			try
			{
				LogController::purgeOldLogEntries();
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

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => "Command Complete.",
			]);
		}
	}
