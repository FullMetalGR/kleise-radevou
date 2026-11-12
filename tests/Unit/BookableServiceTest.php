<?php

use App\Application\Dto\BookableService;
use App\Domain\Availability\SegmentShape;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;

/**
 * @param  list<int>  $staffIds
 */
function bookable(array $staffIds = [4, 2, 9], int $priceMinor = 3500, ?int $approvalHoldHours = 6): BookableService
{
    return new BookableService(7, new SegmentShape(45, 5, 10), 30, 120, 14, $staffIds, new Money($priceMinor, Currency::of('EUR')), PaymentMode::Online, true, $approvalHoldHours, ApprovalTimeout::AutoApprove);
}

it('is the engine\'s view of an active service: its shape, gap, lead time, horizon and the staff members who perform it, ascending', function () {
    $service = bookable();

    expect($service->id)->toBe(7)
        ->and($service->shape->durationMin)->toBe(45)
        ->and($service->shape->bufferBeforeMin)->toBe(5)
        ->and($service->shape->bufferAfterMin)->toBe(10)
        ->and($service->processingGapMin)->toBe(30)
        ->and($service->leadTimeMin)->toBe(120)
        ->and($service->horizonDays)->toBe(14)
        ->and($service->staffIds)->toBe([2, 4, 9])
        ->and($service->price->minor)->toBe(3500)
        ->and($service->price->currency->code)->toBe('EUR')
        ->and($service->paymentMode)->toBe(PaymentMode::Online)
        ->and($service->requiresApproval)->toBeTrue()
        ->and($service->approvalHoldHours)->toBe(6)
        ->and($service->onApprovalTimeout)->toBe(ApprovalTimeout::AutoApprove)
        ->and(bookable(approvalHoldHours: null)->approvalHoldHours)->toBeNull();
});

it('says whether a staff member performs it', function () {
    expect(bookable()->performedBy(4))->toBeTrue()
        ->and(bookable()->performedBy(5))->toBeFalse()
        ->and(bookable([])->performedBy(4))->toBeFalse();
});

it('becomes a chain segment carrying the pick, and refuses to when nobody performs it', function () {
    $segment = bookable()->segment(4);

    expect([$segment->shape->durationMin, $segment->shape->bufferBeforeMin, $segment->shape->bufferAfterMin])->toBe([45, 5, 10])
        ->and($segment->eligibleStaffIds)->toBe([2, 4, 9])
        ->and($segment->pickedStaffId)->toBe(4)
        ->and($segment->processingGapMin)->toBe(30)
        ->and($segment->leadTimeMin)->toBe(120)
        ->and($segment->horizonDays)->toBe(14)
        ->and(bookable()->segment(null)->pickedStaffId)->toBeNull()
        ->and(fn () => bookable([])->segment(null))->toThrow(InvalidArgumentException::class, 'A segment names at least one eligible staff member.');
});

it('refuses a service id below one, a staff member named twice, a negative price and an approval window below one hour', function () {
    expect(fn () => new BookableService(0, new SegmentShape(30), 0, 0, 60, [1], Money::zero(Currency::of('EUR')), PaymentMode::Free, false, null, ApprovalTimeout::Expire))
        ->toThrow(InvalidArgumentException::class, 'A service id is a positive integer, not 0.')
        ->and(fn () => bookable([2, 2]))
        ->toThrow(InvalidArgumentException::class, 'A service names each staff member once, not 2 twice.')
        ->and(fn () => bookable(priceMinor: -1))
        ->toThrow(InvalidArgumentException::class, 'A price is zero or more minor units, not -1.')
        ->and(fn () => bookable(approvalHoldHours: 0))
        ->toThrow(InvalidArgumentException::class, 'An approval window is null or at least one hour, not 0.');
});
