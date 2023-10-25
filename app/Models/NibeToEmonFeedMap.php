<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Factories\HasFactory;
	use Illuminate\Database\Eloquent\Model;

	class NibeToEmonFeedMap extends Model
	{
		use HasFactory;

		protected $fillable = ["parameterId", "title", "localFeedId", "localName", "remoteFeedId", "remoteName"];
	}
