<?php
	namespace App\Console\Commands;

	use App\Http\Controllers\HomeAssistantController;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;

	class AdjustHiveThermostat extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "ha:adjustHiveThermostat";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Check the latest compressor priority and adjust Hive thermostat";

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

				HomeAssistantController::adjustHiveThermostat();

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
