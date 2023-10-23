<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;

	class EmonFeedMap extends Model
	{
		protected $fillable = ["localFeedId", "remoteFeedId", "localName", "remoteName"];
	}
