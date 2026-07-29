<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
            60,
        );

        return (new MailMessage)
            ->subject('Redefinição de senha — '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line(
                'Recebemos uma solicitação para redefinir sua senha em '
                .config('app.name').'.'
            )
            ->action('Redefinir senha', $url)
            ->line("Este link expira em {$minutes} minutos e só pode ser usado uma vez.")
            ->line('Se você não fez esta solicitação, ignore esta mensagem.');
    }
}
