<?php
	namespace App\Models;

	use Carbon\Carbon;
	use Illuminate\Database\Eloquent\Model;

	class ApiLog extends Model
	{
		protected $fillable = ["method", "endpoint", "payload", "hash", "ignored", "processed"];
		protected $table = "api_logs";

		public function markAsProcessed()
		{
			$this->processed = Carbon::now()->format("Y-m-d H:i:s");
			$this->save();
		}
	}
