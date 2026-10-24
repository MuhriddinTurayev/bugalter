<?php

namespace App\Ai;

use App\Enums\Actor;
use App\Enums\ProposalStatus;
use App\Models\AiProposal;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Audit;
use App\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AI data writes are proposals (PLAN section 6). A person approves them, unless the owner enabled auto-apply
 * for the action type and the confidence reaches the owner's threshold; auto-applied changes are logged with
 * actor "ai".
 */
class ProposalService
{
    public function __construct(private readonly CurrentCompany $company, private readonly Audit $audit) {}

    public function proposeCategory(Transaction $transaction, Category $category, int $confidence, ?string $reason, string $source = 'ai', ?int $conversationId = null): AiProposal
    {
        if ($category->type !== $transaction->type || ! $category->is_active) {
            throw new InvalidArgumentException(__('ai.errors.category_type'));
        }

        $confidence = max(0, min(100, $confidence));

        $proposal = AiProposal::query()->updateOrCreate(
            ['type' => AiProposal::CLASSIFY_TRANSACTION, 'subject_type' => 'transaction', 'subject_id' => $transaction->id, 'status' => ProposalStatus::Pending],
            [
                'payload' => ['category_id' => $category->id, 'category' => $category->label()],
                'confidence' => $confidence,
                'reason' => $reason ? mb_substr($reason, 0, 500) : null,
                'source' => $source,
                'ai_conversation_id' => $conversationId,
            ],
        );

        $auto = $this->company->settings()->autoApply(AiProposal::CLASSIFY_TRANSACTION);

        if ($transaction->category_id === null && $auto['enabled'] && $confidence >= (int) $auto['min_confidence']) {
            $this->applyChange($proposal, ProposalStatus::AutoApplied, $source === 'ai' ? Actor::Ai : Actor::Autopilot, null);
        }

        return $proposal->refresh();
    }

    public function apply(AiProposal $proposal, User $user): AiProposal
    {
        if ($proposal->status !== ProposalStatus::Pending) {
            throw new InvalidArgumentException(__('ai.errors.proposal_decided'));
        }

        $this->applyChange($proposal, ProposalStatus::Applied, Actor::User, $user->name);

        return $proposal->refresh();
    }

    public function reject(AiProposal $proposal, User $user): AiProposal
    {
        if ($proposal->status !== ProposalStatus::Pending) {
            throw new InvalidArgumentException(__('ai.errors.proposal_decided'));
        }

        $proposal->update(['status' => ProposalStatus::Rejected, 'decided_by_name' => $user->name, 'decided_at' => now()]);
        $this->audit->record('ai.proposal_rejected', ['proposal' => $proposal->id, 'transaction' => $proposal->subject_id]);

        return $proposal;
    }

    private function applyChange(AiProposal $proposal, ProposalStatus $status, Actor $actor, ?string $actorName): void
    {
        DB::transaction(function () use ($proposal, $status, $actor, $actorName) {
            $transaction = Transaction::query()->lockForUpdate()->find($proposal->subject_id);
            $category = Category::query()->find($proposal->payload['category_id'] ?? 0);

            if ($transaction === null || $category === null) {
                $proposal->update(['status' => ProposalStatus::Rejected, 'decided_by_name' => $actorName ?? $actor->label(), 'decided_at' => now(), 'reason' => __('ai.errors.subject_missing')]);

                return;
            }

            $before = $transaction->category_id;
            $transaction->update(['category_id' => $category->id]);
            $proposal->update(['status' => $status, 'decided_by_name' => $actorName ?? $actor->label(), 'decided_at' => now()]);

            $this->audit->record(
                $status === ProposalStatus::AutoApplied ? 'ai.proposal_auto_applied' : 'ai.proposal_applied',
                ['proposal' => $proposal->id, 'transaction' => $transaction->id, 'category' => $category->label(), 'before' => $before, 'confidence' => $proposal->confidence],
                $actor,
                $proposal->reason,
                $actorName,
            );
        });
    }
}
