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
			Schema::create("emon_feed_maps", function(Blueprint $table)
			{
				$table->integer("localFeedId");
				$table->string("localName");
				$table->integer("remoteFeedId");
				$table->string("remoteName");
			});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() : void
		{
			Schema::dropIfExists("emon_feed_maps");
		}
	};
