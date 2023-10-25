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
			Schema::create("nibe_feed_items", function(Blueprint $table)
			{
				$table->id();
				$table->timestamps();
				$table->integer("parameterId");
				$table->integer("localFeedId")->nullable();
				$table->bigInteger("timestamp");
				$table->integer("rawValue");
				$table->integer("syncAttempts")->default(0);
				$table->string("syncStatus");
			});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() : void
		{
			Schema::dropIfExists("nibe_feed_items");
		}
	};
