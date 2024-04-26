<?php
	namespace App\Console\Commands;

	use App\Http\Controllers\NibeController;
	use App\Models\ActivityLog;
	use Illuminate\Console\Command;

	class NibeDmOverride extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "nibe:dmOverride";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Set NIBE Degree Minutes parameter value";

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

				NibeController::dmOverride();

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
