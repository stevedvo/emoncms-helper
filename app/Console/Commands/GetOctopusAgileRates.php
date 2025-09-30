<?php
	namespace App\Console\Commands;

	use Throwable;
	use App\Http\Controllers\OctopusController;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;

	class GetOctopusAgileRates extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "octopus:getAgileRates";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Get the latest Octopus Agile Rates";

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

				OctopusController::tryGetAgileRates();

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
