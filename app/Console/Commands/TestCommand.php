<?php
	namespace App\Console\Commands;

	use Throwable;
	use App\APIs\EmonAPI;
	use App\Http\Controllers\EmonController;
	use App\Models\ActivityLog;
	use Carbon\CarbonImmutable;
	use Illuminate\Console\Command;

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

				// EmonController::getForecastRoomTemperatureData();
				$syncSuccess = EmonAPI::postInputData("local", CarbonImmutable::now()->startOfMinute()->setTimezone("UTC")->format("U"), "emonth2_23", json_encode(["temperature_forecast" => 21.6]));


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
