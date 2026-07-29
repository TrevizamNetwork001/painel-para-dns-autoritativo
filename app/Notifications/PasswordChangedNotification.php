<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $changedAt,
        private readonly ?string $ipAddress,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Senha alterada — '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('A senha da sua conta foi alterada com sucesso.')
            ->line('Data e hora: '.$this->changedAt);

        if ($this->ipAddress !== null) {
            $message->line('Endereço IP: '.$this->ipAddress);
        }

        return $message->line(
            'Se você não reconhece esta alteração, contate o administrador da instalação imediatamente.'
        );
    }
}
