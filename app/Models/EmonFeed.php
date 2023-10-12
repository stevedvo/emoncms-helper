<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;

	class EmonFeed extends Model
	{
		protected $fillable = ["id", "userid", "name", "tag", "public", "size", "engine", "processList", "unit", "time", "value"];
	}
