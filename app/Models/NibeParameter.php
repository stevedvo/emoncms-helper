<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Factories\HasFactory;
	use Illuminate\Database\Eloquent\Model;

	class NibeParameter extends Model
	{
		use HasFactory;

		protected $fillable = ["parameterId", "title", "designation", "unit"];
	}
