<?php

namespace App\Notifications\PaymentLink;

use App\Constants\PaymentGatewayConst;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class UserNotification extends Notification
{
    use Queueable;

    private $user;
    private $data;
    private $trx_id;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($user, $data, $trx_id)
    {
        $this->user = $user;
        $this->data = $data;
        $this->trx_id = $trx_id;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $user = $this->user;
        $data = $this->data;
        $trx_id = $this->trx_id;

        $date = Carbon::now();
        $datetime = dateFormat('Y-m-d h:i:s A', $date);

        if($data['transaction_type'] == PaymentGatewayConst::TYPEPAYLINK){
            $type = 'payment_link';
        }else{
            $type = 'invoice';
        }

        return (new MailMessage)
            ->greeting("Hello ".$user->fullName." !")
            ->subject("Payment Link Transaction via ".$data['transaction_type'])
            ->line("Your payment request successfully via ".$data['transaction_type']." , details transactions:")
            ->line("Request Amount: " . getAmount($data['amount'],2).' '. $data[$type]->currency)
            ->line("Exchange Rate: " ." 1 ". $data['charge_calculation']['receiver_currency_code'].' = '. getAmount($data['charge_calculation']['exchange_rate'],2).' '.$data['charge_calculation']['sender_cur_code'])
            ->line("Fees & Charges: " . $data['charge_calculation']['conversion_charge'].' '. $data['charge_calculation']['receiver_currency_code'])
            ->line("Will Get: " . getAmount($data['charge_calculation']['conversion_payable'],2).' '. $data['charge_calculation']['receiver_currency_code'])
            ->line("Transaction Id: " .$trx_id)
            ->line("Status: Success")
            ->line("Date And Time: " .$datetime)
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
