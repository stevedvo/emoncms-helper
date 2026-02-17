<?php
	namespace App\Mail;

	use Illuminate\Bus\Queueable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Mail\Mailable;
	use Illuminate\Queue\SerializesModels;

	class NibeOffline extends Mailable
	{
		use Queueable, SerializesModels;

		public $currentCount;

		/**
		 * Create a new message instance.
		 *
		 * @param $currentCount
		 */
		public function __construct(int $currentCount)
		{
			$this->currentCount  = $currentCount;
		}

		/**
		 * Build the message.
		 *
		 * @return $this
		 */
		public function build()
		{
			return $this->from(config('mail.from.address'))->subject("Nibe Offline?")->view('emails.nibe_offline');
		}
	}
