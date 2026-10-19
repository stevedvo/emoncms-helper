<?php

namespace Tests\Support;

use App\Services\ControlSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait ControlPanelDatabase
{
	protected function prepareControlPanelDatabase(): void
	{
		config()->set('database.default', 'control_panel_testing');
		config()->set('database.connections.control_panel_testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
		foreach (ControlSettings::definitions() as $key => $definition) {
			config()->set($key, $definition['default']);
		}
		Schema::create('users', function (Blueprint $table) {
			$table->id(); $table->string('name'); $table->string('email')->unique();
			$table->timestamp('email_verified_at')->nullable(); $table->string('password');
			$table->rememberToken(); $table->timestamps();
		});
		Schema::create('settings', function (Blueprint $table) {
			$table->id(); $table->timestamps(); $table->string('key'); $table->text('value');
		});
		Schema::create('activity_logs', function (Blueprint $table) {
			$table->id(); $table->timestamps(); $table->string('controller');
			$table->string('method'); $table->string('level'); $table->text('message');
		});
		Schema::create('nibe_feed_items', function (Blueprint $table) {
			$table->id(); $table->timestamps(); $table->integer('parameterId');
			$table->bigInteger('timestamp'); $table->decimal('rawValue');
			$table->integer('syncAttempts')->default(0); $table->string('syncStatus');
		});
		Schema::create('nibe_parameters', function (Blueprint $table) {
			$table->integer('parameterId')->primary(); $table->string('title');
			$table->string('designation')->default(''); $table->string('unit')->default('');
		});
	}
}
