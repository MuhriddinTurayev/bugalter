<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A notification stored as a translation key plus parameters, so it renders in the reader's language. */
class AppNotification extends Notification
{
    /** @param  array<string, scalar>  $params */
    public function __construct(
        private readonly string $key,
        private readonly array $params = [],
        private readonly ?string $url = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['key' => $this->key, 'params' => $this->params, 'url' => $this->url];
    }
}
