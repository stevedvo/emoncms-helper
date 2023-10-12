<?php
	namespace App\Console\Commands;

	use Illuminate\Console\Command;
	use Illuminate\Support\Facades\Log;

	class SchedulerTest extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "schedule:test";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Test the Scheduler is running";

		/**
		 * Execute the console command.
		 *
		 * @return void
		 */
		public function handle() : void
		{
			Log::info(__CLASS__."->".__FUNCTION__."(): command successful");
		}
	}
