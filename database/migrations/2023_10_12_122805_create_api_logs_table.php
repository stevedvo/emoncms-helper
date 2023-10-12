<?php
	use Illuminate\Database\Migrations\Migration;
	use Illuminate\Database\Schema\Blueprint;
	use Illuminate\Support\Facades\Schema;

	return new class extends Migration
	{
		/**
		 * Run the migrations.
		 *
		 * @return void
		 */
		public function up() : void
		{
			Schema::create("api_logs", function(Blueprint $table)
			{
				$table->id();
				$table->timestamps();
				$table->string("method")->nullable()->default(null);
				$table->string("endpoint")->nullable()->default(null);
				$table->longText("payload")->nullable()->default(null);
				$table->string("hash", 32)->nullable()->default(null)->index();
				$table->boolean("ignored")->default(false);
				$table->dateTime("processed")->nullable()->default(null);
			});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() : void
		{
			Schema::dropIfExists("api_logs");
		}
	};
