<?php
	namespace App\Console\Commands;

	use App\Models\User;
	use Illuminate\Console\Command;
	use Illuminate\Support\Facades\Hash;
	use Illuminate\Support\Facades\Validator;

	class CreateControlPanelUser extends Command
	{
		protected $signature = 'app:create-user {email? : Email address for the control-panel account}';
		protected $description = 'Create a control-panel account with a privately entered password';

		public function handle(): int
		{
			$data =
			[
				'email'                 => $this->argument('email') ?? $this->ask('Email address'),
				'name'                  => $this->ask('Name'),
				'password'              => $this->secret('Password (at least 12 characters)'),
				'password_confirmation' => $this->secret('Confirm password'),
			];

			$validator = Validator::make($data,
			[
				'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
				'name'     => ['required', 'string', 'max:255'],
				'password' => ['required', 'string', 'min:12', 'confirmed'],
			]);

			if ($validator->fails())
			{
				foreach ($validator->errors()->all() as $error)
				{
					$this->error($error);
				}

				return self::FAILURE;
			}

			User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
			$this->info('Account created. You can now sign in to the control panel.');

			return self::SUCCESS;
		}
	}
