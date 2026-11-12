<?php

use App\Domain\Enum\ApprovalTimeout;

it('has the two outcomes, expire first, spelled as the columns store them', function () {
    expect(array_map(fn (ApprovalTimeout $outcome): string => $outcome->value, ApprovalTimeout::cases()))
        ->toBe(['expire', 'auto_approve'])
        ->and(ApprovalTimeout::from('auto_approve'))->toBe(ApprovalTimeout::AutoApprove);
});

it('resolves a chain to auto_approve only when every segment requiring approval says so, and to expire for a chain requiring no approval', function () {
    expect(ApprovalTimeout::acrossChain([ApprovalTimeout::AutoApprove, ApprovalTimeout::AutoApprove]))->toBe(ApprovalTimeout::AutoApprove)
        ->and(ApprovalTimeout::acrossChain([ApprovalTimeout::AutoApprove, ApprovalTimeout::Expire]))->toBe(ApprovalTimeout::Expire)
        ->and(ApprovalTimeout::acrossChain([ApprovalTimeout::Expire]))->toBe(ApprovalTimeout::Expire)
        ->and(ApprovalTimeout::acrossChain([ApprovalTimeout::AutoApprove]))->toBe(ApprovalTimeout::AutoApprove)
        ->and(ApprovalTimeout::acrossChain([]))->toBe(ApprovalTimeout::Expire);
});
