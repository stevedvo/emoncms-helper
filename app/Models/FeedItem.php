<?php
	namespace App\Models;

	use Illuminate\Database\Eloquent\Model;

	class FeedItem extends Model
	{
		protected $fillable = ["id", "localFeedId", "remoteFeedId", "timestamp", "value", "syncAttempts", "syncStatus"];
	}
