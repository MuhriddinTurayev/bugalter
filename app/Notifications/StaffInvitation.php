<?php

namespace App\Notifications;

use App\Models\PlatformStaff;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification
{
    public function __construct(private readonly PlatformStaff $staff, private readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('platform.staff.invitation_subject'))
            ->greeting(__('platform.invitation.greeting', ['name' => $this->staff->name]))
            ->line(__('platform.staff.invitation_line', ['days' => config('platform.invitation_days')]))
            ->action(__('platform.invitation.action'), $this->url)
            ->line(__('platform.invitation.footer'));
    }
}
