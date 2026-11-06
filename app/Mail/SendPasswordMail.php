<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class SendPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;
    public $isNewAccount;

    public function __construct($userId, $password, $isNewAccount = false)
    {
        $this->user = User::findOrFail($userId);
        $this->password = $password;
        $this->isNewAccount = $isNewAccount;
    }

    public function build()
    {
        $subject = $this->isNewAccount
            ? 'Your New Account Credentials'
            : 'Your Password Has Been Reset';

        return $this->subject($subject)
            ->view('emails.password_send');
        // return $this->subject('Your Account Credentials')
        //            ->view('emails.password_send');
    }
}
