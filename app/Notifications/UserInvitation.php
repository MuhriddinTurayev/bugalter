<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A company user invited by the owner sets their own password through this link. */
class UserInvitation extends Notification
{
    public function __construct(private readonly string $company, private readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('ui.users.mail.subject', ['company' => $this->company]))
            ->greeting(__('ui.users.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('ui.users.mail.line', ['company' => $this->company, 'role' => $notifiable->role->label(), 'days' => config('platform.invitation_days')]))
            ->action(__('ui.users.mail.action'), $this->url)
            ->line(__('ui.users.mail.footer'));
    }
}
