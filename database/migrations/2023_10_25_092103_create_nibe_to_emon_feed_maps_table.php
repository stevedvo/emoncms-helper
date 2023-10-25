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
			Schema::create("nibe_to_emon_feed_maps", function(Blueprint $table)
			{
				$table->integer("parameterId")->primary();
				$table->string("title");
				$table->integer("localFeedId")->nullable();
				$table->string("localName")->nullable();
				$table->integer("remoteFeedId")->nullable();
				$table->string("remoteName")->nullable();
			});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() : void
		{
			Schema::dropIfExists("nibe_to_emon_feed_maps");
		}
	};
