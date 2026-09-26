<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ConviteDeUsuario extends Notification
{
    public function __construct(public readonly string $token, public readonly string $empresa) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Usuario $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/definir-senha?token='.$this->token.'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject("Convite para {$this->empresa}")
            ->greeting('Olá!')
            ->line("Você foi convidado para {$this->empresa}. Defina sua senha para entrar.")
            ->action('Definir senha', $url)
            ->line('O link expira em 72 horas.');
    }
}
