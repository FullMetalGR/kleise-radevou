<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Application\Dto\FigureFilter;
use App\Application\Dto\FigureRange;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of the Reports screen: range, one of Reports' eight words (FigureRange::REPORTS) or
 * custom, for custom from and to, two dates with the To date inclusive and at most
 * FigureRange::MOST_DAYS days between them, and staff and service, each an id. Read tolerantly by
 * TolerantQuery, as the lists and the calendar's window are: a word this does not know, text no
 * column can compare, or a custom pair that is malformed, inverted or too long reads as this week,
 * and a member or a service the screen does not offer as all of them, rather than a 422, because
 * the screen is a read and a stale bookmark must still land on it. The dates beside a named range
 * are not read.
 */
final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The range asked for.
     *
     * @param  string  $today  the tenant's today, Y-m-d
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     */
    public function range(string $today, int $startOfWeek): FigureRange
    {
        $word = TolerantQuery::text($this, 'range');

        if ($word === FigureRange::CUSTOM) {
            return FigureRange::custom(TolerantQuery::day($this, 'from'), TolerantQuery::day($this, 'to'))
                ?? FigureRange::named(null, $today, $startOfWeek);
        }

        return FigureRange::named($word, $today, $startOfWeek, FigureRange::REPORTS);
    }

    /**
     * The member and the service asked for, each kept only where the screen offers it.
     *
     * @param  list<int>  $staff  the members offered
     * @param  list<int>  $services  the services offered
     */
    public function filter(array $staff, array $services): FigureFilter
    {
        $member = TolerantQuery::id($this, 'staff');
        $service = TolerantQuery::id($this, 'service');

        return new FigureFilter(
            in_array($member, $staff, true) ? $member : null,
            in_array($service, $services, true) ? $service : null,
        );
    }
}
