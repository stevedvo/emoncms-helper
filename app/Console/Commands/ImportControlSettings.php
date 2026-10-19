<?php
	namespace App\Console\Commands;

	use App\Services\ControlSettings;
	use Illuminate\Console\Command;

	class ImportControlSettings extends Command
	{
		protected $signature = 'settings:import';
		protected $description = 'Import missing operating parameters from the effective configuration, preserving saved database settings';

		public function handle(): int
		{
			$count = ControlSettings::initialize();
			$this->info('Imported '.$count.' operating parameters. Existing database settings were preserved.');
			return self::SUCCESS;
		}
	}
