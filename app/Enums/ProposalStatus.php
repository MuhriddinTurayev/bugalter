<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case AutoApplied = 'auto_applied';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('ai.proposal_statuses.'.$this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Applied, self::AutoApplied => 'success',
            self::Rejected => 'neutral',
        };
    }
}
