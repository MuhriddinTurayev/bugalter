<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\CompanySetting;
use App\Models\PlatformStaff;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CompanyOwnerInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a company: a row in the central database, its own database with all tables,
 * its subdomain, the company profile and an invited owner.
 */
class TenantProvisioningService
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /** @return array{available: bool, reason: ?string} reason is one of invalid, reserved, taken */
    public function slugAvailability(string $slug): array
    {
        $slug = Str::lower(trim($slug));

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug) || str_contains($slug, '--')) {
            return ['available' => false, 'reason' => 'invalid'];
        }

        if (in_array($slug, config('platform.reserved_slugs'), true) || $slug === Str::lower((string) config('platform.domain'))) {
            return ['available' => false, 'reason' => 'reserved'];
        }

        if (Tenant::query()->whereKey($slug)->exists()) {
            return ['available' => false, 'reason' => 'taken'];
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * @param  array{name: string, stir: ?string, slug: string, plan_id: int, owner_name: string, owner_email: string, owner_phone: ?string}  $data
     * @return array{tenant: Tenant, url: string, invitation_url: string}
     *
     * @throws ValidationException
     */
    public function provision(array $data, ?PlatformStaff $actor = null): array
    {
        $slug = Str::lower(trim($data['slug']));
        $availability = $this->slugAvailability($slug);

        if (! $availability['available']) {
            throw ValidationException::withMessages(['slug' => __('platform.companies.slug_'.$availability['reason'])]);
        }

        try {
            // Creating the tenant fires the pipeline that creates its database and runs all migrations.
            $tenant = Tenant::create([
                'id' => $slug,
                'name' => $data['name'],
                'stir' => $data['stir'] ?? null,
                'plan_id' => $data['plan_id'],
                'status' => TenantStatus::Provisioning,
                'owner_name' => $data['owner_name'],
                'owner_email' => $data['owner_email'],
                'owner_phone' => $data['owner_phone'] ?? null,
            ]);

            $tenant->domains()->create(['domain' => $tenant->host()]);

            // Local development aliases such as demo1.localhost (empty outside APP_ENV=local).
            foreach (config('platform.local_domains') as $localDomain) {
                $tenant->domains()->create(['domain' => $tenant->id.'.'.$localDomain]);
            }

            $token = Str::random(48);

            $tenant->run(function () use ($tenant, $data, $token) {
                CompanySetting::create(['name' => $tenant->name, 'stir' => $tenant->stir]);
                $this->createOwner($data['owner_name'], $data['owner_email'], $data['owner_phone'] ?? null, $token);
            });

            $tenant->update(['status' => TenantStatus::Active, 'owner_invited_at' => now()]);
        } catch (Throwable $e) {
            $this->cleanUpFailed($slug);
            $this->audit->record('company.provisioning_failed', $slug, null, ['error' => Str::limit($e->getMessage(), 300)], $actor);

            throw $e;
        }

        $invitationUrl = $this->invitationUrl($tenant, $token);
        $this->sendInvitation($tenant, $invitationUrl);

        $this->audit->record('company.created', $tenant->id, null, [
            'name' => $tenant->name,
            'plan_id' => $tenant->plan_id,
            'owner_email' => $tenant->owner_email,
        ], $actor);

        return ['tenant' => $tenant, 'url' => $tenant->url(), 'invitation_url' => $invitationUrl];
    }

    /** Issues a fresh invitation link to the company's owner and sends it; returns the link. */
    public function resendInvitation(Tenant $tenant, ?PlatformStaff $actor = null): string
    {
        $token = Str::random(48);

        $tenant->run(function () use ($token) {
            User::query()->where('role', Role::Owner)->orderBy('id')->firstOrFail()->forceFill([
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => now()->addDays(config('platform.invitation_days')),
            ])->save();
        });

        $tenant->update(['owner_invited_at' => now()]);

        $url = $this->invitationUrl($tenant, $token);
        $this->sendInvitation($tenant, $url);
        $this->audit->record('company.invitation_resent', $tenant->id, null, [], $actor);

        return $url;
    }

    public function invitationUrl(Tenant $tenant, string $token): string
    {
        return $tenant->url().'/invitation/'.$token;
    }

    private function createOwner(string $name, string $email, ?string $phone, string $token): User
    {
        $owner = new User(['name' => $name, 'email' => Str::lower($email), 'phone' => $phone, 'role' => Role::Owner, 'locale' => 'uz']);

        $owner->forceFill([
            'is_active' => true,
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_expires_at' => now()->addDays(config('platform.invitation_days')),
        ])->save();

        return $owner;
    }

    private function sendInvitation(Tenant $tenant, string $url): void
    {
        try {
            Notification::route('mail', $tenant->owner_email)->notify(new CompanyOwnerInvitation($tenant, $url));
        } catch (Throwable $e) {
            // The link is shown in the panel too, so a mail failure must not undo provisioning.
            $this->audit->record('company.invitation_mail_failed', $tenant->id, null, ['error' => Str::limit($e->getMessage(), 200)]);
        }
    }

    private function cleanUpFailed(string $slug): void
    {
        try {
            Tenant::query()->find($slug)?->delete(); // also drops the database through the tenancy pipeline
        } catch (Throwable) {
            // Nothing more can be done here; the audit log records the failure.
        }
    }
}
