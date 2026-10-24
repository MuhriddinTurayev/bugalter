<?php

namespace App\Services;

use App\Enums\Actor;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The company's own audit log. Every change of business data and every autonomous action is written here
 * with its actor (user, ai, autopilot, system), the reason and the approval or mandate reference.
 */
class Audit
{
    /** @param  array<string, mixed>  $details */
    public function record(string $action, array $details = [], Actor $actor = Actor::User, ?string $reason = null, ?string $actorName = null): AuditLog
    {
        return AuditLog::create([
            'actor_type' => $actor->value,
            'actor_name' => $actorName ?? $this->nameFor($actor),
            'action' => $action,
            'reason' => $reason,
            'details' => $details === [] ? null : $details,
        ]);
    }

    private function nameFor(Actor $actor): string
    {
        if ($actor === Actor::User) {
            /** @var User|null $user */
            $user = Auth::guard('web')->user();

            return $user->name ?? __('ui.actors.user');
        }

        return $actor->label();
    }
}
