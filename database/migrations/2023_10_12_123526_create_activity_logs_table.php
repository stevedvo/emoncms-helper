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
			Schema::create("activity_logs", function(Blueprint $table)
			{
				$table->id();
				$table->timestamps();
				$table->string("controller")->nullable()->default(null);
				$table->string("method")->nullable()->default(null);
				$table->string("level")->nullable()->default(null);
				$table->longText("message")->nullable()->default(null);
			});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() : void
		{
			Schema::dropIfExists("activity_logs");
		}
	};
