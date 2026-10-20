<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;

	class SavedLogSearch extends Model
	{
		protected $fillable = ['user_id', 'type', 'name', 'criteria'];
		protected $casts = ['criteria' => 'array'];
	}
