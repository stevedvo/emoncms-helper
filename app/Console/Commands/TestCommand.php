<?php
	namespace App\Console\Commands;

	use Throwable;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;
	use App\Http\Controllers\EmonController;

	class TestCommand extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "command:test";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Command for testing functions";

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

				EmonController::getLatestRoomTemperatureData();

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
