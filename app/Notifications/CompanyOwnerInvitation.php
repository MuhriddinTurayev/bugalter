<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyOwnerInvitation extends Notification
{
    public function __construct(private readonly Tenant $tenant, private readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('platform.invitation.subject', ['company' => $this->tenant->name]))
            ->greeting(__('platform.invitation.greeting', ['name' => $this->tenant->owner_name]))
            ->line(__('platform.invitation.line', ['company' => $this->tenant->name, 'days' => config('platform.invitation_days')]))
            ->action(__('platform.invitation.action'), $this->url)
            ->line(__('platform.invitation.footer'));
    }
}
