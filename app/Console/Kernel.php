<?php
	namespace App\Console;

	use Illuminate\Console\Scheduling\Schedule;
	use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

	class Kernel extends ConsoleKernel
	{
		/**
		 * Define the application's command schedule.
		 */
		protected function schedule(Schedule $schedule) : void
		{
			$schedule->command("nibe:priorityHeartbeat")->everyTenSeconds();
			$schedule->command("nibe:getData")->everyMinute();
			$schedule->command("ha:adjustHiveThermostat")->everyMinute();
			$schedule->command("emon:sync")->everyThirtyMinutes();
			$schedule->command("logs:cleanUp")->daily();
			$schedule->command("admin:emails")->dailyAt("06:00");
			$schedule->command("octopus:getAgileRates")->dailyAt("16:05")->timezone("Europe/London");
		}

		/**
		 * Register the commands for the application.
		 */
		protected function commands(): void
		{
			$this->load(__DIR__.'/Commands');

			require base_path('routes/console.php');
		}
	}
