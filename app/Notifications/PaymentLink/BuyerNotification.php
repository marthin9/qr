<?php

namespace App\Notifications\PaymentLink;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use App\Constants\PaymentGatewayConst;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class BuyerNotification extends Notification
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

        if($data['transaction_type'] == PaymentGatewayConst::TYPEPAYLINK){
            $type = 'payment_link';
        }else{
            $type = 'invoice';
        }

        $date = Carbon::now();
        $datetime = dateFormat('Y-m-d h:i:s A', $date);
        return (new MailMessage)
            ->greeting("Hello ".$user['name']." !")
            ->subject("Payment Link Transaction via ". $data['transaction_type'])
            ->line("Your payment request successfully via ".$data[$type]->currency." , details transactions:")
            ->line("Request Amount: " . getAmount($data['amount'],2).' '. $data[$type]->currency)
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
