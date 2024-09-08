<?php
	namespace App\Mail;

	use Illuminate\Bus\Queueable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Mail\Mailable;
	use Illuminate\Queue\SerializesModels;

	class AgileRates extends Mailable
	{
		use Queueable, SerializesModels;

		public $cheapestPeriods;

		/**
		 * Create a new message instance.
		 *
		 * @param $cheapestPeriods
		 */
		public function __construct(array $cheapestPeriods)
		{
			$this->cheapestPeriods = $cheapestPeriods;
		}

		/**
		 * Build the message.
		 *
		 * @return $this
		 */
		public function build()
		{
			return $this->from(config('mail.from.address'))->subject("Agile Rates")->view('emails.agile_rates');
		}
	}
