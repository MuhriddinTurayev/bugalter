<?php

namespace App\Autopilot;

use App\Enums\Actor;
use App\Enums\AutopilotLevel;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Mandate;
use App\Models\SigningKey;
use App\Services\Audit;
use App\Services\CompanyNotifier;
use App\Services\CurrentCompany;
use App\Tax\TaxObligations;
use Carbon\CarbonImmutable;

/**
 * Daily, per company: overdue invoices, tax deadlines coming up, and ERI key expiry (PLAN section 9: warn 30,
 * 14 and 3 days before; after expiry the key is unusable and autopilot drops to level 1).
 */
class DailyReminders
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly TaxObligations $obligations,
        private readonly CompanyNotifier $notifier,
        private readonly Audit $audit,
    ) {}

    /** @return array<string, int> */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $this->company->forget();

        return [
            'overdue' => $this->overdueDocuments($today),
            'deadlines' => $this->deadlines($today),
            'keys' => $this->keys($today),
            'mandate' => $this->mandateExpiry(),
        ];
    }

    /** Levels 2 and 3 need an active mandate: when the last one expires, the autopilot falls back to level 1. */
    private function mandateExpiry(): int
    {
        $settings = $this->company->settings();

        if (! $settings->exists || ! $settings->autopilot()->needsMandate() || Mandate::hasActive()) {
            return 0;
        }

        $settings->forceFill(['autopilot_level' => AutopilotLevel::Prepare])->save();
        $this->audit->record('autopilot.level_lowered', ['to' => 1], Actor::System, __('autopilot.notes.mandate_revoked'));
        $this->notifier->owners('autopilot.notifications.mandate_expired', [], '/autopilot');

        return 1;
    }

    private function overdueDocuments(CarbonImmutable $today): int
    {
        $overdue = Document::query()->where('direction', 'out')->where('status', DocumentStatus::Sent)->whereDate('due_date', '<', $today)->count();

        if ($overdue > 0) {
            $this->notifier->managers('tax.notifications.overdue_documents', ['count' => $overdue], '/documents?status=sent');
        }

        return $overdue;
    }

    private function deadlines(CarbonImmutable $today): int
    {
        $count = 0;

        foreach ($this->obligations->calendar($this->company->settings(), 1, $today) as $row) {
            if ($row['due_date'] === null || ($row['report'] !== null && $row['report']->status->isFinal())) {
                continue;
            }

            $days = (int) $today->diffInDays($row['due_date'], false);

            if (in_array($days, [7, 3, 1, 0], true)) {
                $this->notifier->managers('tax.notifications.deadline', [
                    'report' => $row['type']->label().' · '.$row['period']->label(),
                    'date' => $row['due_date']->format('d.m.Y'),
                    'days' => $days,
                ], '/tax');
                $count++;
            }
        }

        return $count;
    }

    private function keys(CarbonImmutable $today): int
    {
        $count = 0;

        foreach (SigningKey::query()->where('status', 'active')->get() as $key) {
            $days = (int) $today->diffInDays($key->valid_to, false);

            if ($days < 0) {
                $key->update(['status' => 'expired']);
                $this->audit->record('signing_key.expired', ['key' => $key->id, 'label' => $key->label], Actor::System);
                $this->notifier->owners('autopilot.notifications.key_expired', ['label' => $key->label], '/signing-keys');

                $settings = $this->company->settings();
                if ($settings->exists && $settings->autopilot() === AutopilotLevel::Auto) {
                    $settings->forceFill(['autopilot_level' => AutopilotLevel::Prepare])->save();
                    $this->audit->record('autopilot.level_lowered', ['from' => 3, 'to' => 1], Actor::System, __('autopilot.notes.key_expired'));
                }

                $count++;

                continue;
            }

            // Every threshold already reached counts as warned, so one warning is sent per threshold crossed.
            $sent = $key->expiry_warnings_sent ?? [];
            $reached = array_values(array_diff(array_filter(config('signing.expiry_warning_days'), fn (int $t) => $days <= $t), $sent));

            if ($reached !== []) {
                $this->notifier->owners('autopilot.notifications.key_expiring', ['label' => $key->label, 'days' => $days], '/signing-keys');
                $key->update(['expiry_warnings_sent' => array_values(array_unique([...$sent, ...$reached]))]);
                $count++;
            }
        }

        return $count;
    }
}
