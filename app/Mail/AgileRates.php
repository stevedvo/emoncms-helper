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
		public $expensivePeriods;

		/**
		 * Create a new message instance.
		 *
		 * @param $cheapestPeriods
		 * @param $expensivePeriods
		 */
		public function __construct(array $cheapestPeriods, array $expensivePeriods)
		{
			$this->cheapestPeriods  = $cheapestPeriods;
			$this->expensivePeriods = $expensivePeriods;
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
