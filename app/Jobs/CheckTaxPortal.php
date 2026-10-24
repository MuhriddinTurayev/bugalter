<?php

namespace App\Jobs;

use App\Enums\Actor;
use App\Portal\PortalException;
use App\Portal\TaxPortalCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A cabinet check in the background: the robot can take minutes. Runs inside the company's context
 * (QueueTenancyBootstrapper). Never retried by itself: every attempt signs a login with the company key.
 */
class CheckTaxPortal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly Actor $actor,
        public readonly ?string $actorName = null,
    ) {}

    public function handle(TaxPortalCheck $check): void
    {
        try {
            $check->run($this->actor, $this->actorName);
        } catch (PortalException) {
            // Another visit is running; its result is what the page shows.
        }
    }
}
