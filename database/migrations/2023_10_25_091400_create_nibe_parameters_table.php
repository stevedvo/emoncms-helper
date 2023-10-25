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
			Schema::create("nibe_parameters", function(Blueprint $table)
			{
				$table->integer("parameterId")->primary();
				$table->string("title");
				$table->string("designation");
				$table->string("unit");
			});
		}

		/**
		 * Reverse the migrations.
		 *
		 * @return void
		 */
		public function down() : void
		{
			Schema::dropIfExists("nibe_parameters");
		}
	};
