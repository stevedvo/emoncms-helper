<?php
	namespace App\Console\Commands;

	use Throwable;
	use App\Http\Controllers\NibeController;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;

	class NibePriorityHeartbeat extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "nibe:priorityHeartbeat";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Get the latest value for the 'priority' parameter and resend to Emon";

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

				NibeController::priorityHeartbeat();

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
