<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/app')
            ->group(__DIR__ . '/../Http/routes.php');

        ResetPassword::createUrlUsing(function (object $usuario, string $token): string {
            $base = rtrim((string) config('app.frontend_url'), '/');

            return $base . '/redefinir-senha?token=' . $token . '&email=' . urlencode($usuario->email);
        });

        ResetPassword::toMailUsing(function (object $usuario, string $token): MailMessage {
            $url = call_user_func(ResetPassword::$createUrlCallback, $usuario, $token);

            return (new MailMessage())
                ->subject('Redefinição de senha')
                ->greeting('Olá!')
                ->line('Recebemos um pedido para redefinir a senha da sua conta.')
                ->action('Redefinir senha', $url)
                ->line('O link expira em 60 minutos. Se você não pediu, ignore este e-mail.');
        });
    }
}
