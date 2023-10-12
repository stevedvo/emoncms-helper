<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;

	class ActivityLog extends Model
	{
		protected $fillable = ["controller", "method", "level", "message"];
		protected $table = "activity_logs";
	}
