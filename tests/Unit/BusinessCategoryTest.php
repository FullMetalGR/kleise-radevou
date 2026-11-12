<?php

use App\Tenancy\BusinessCategory;

/*
 * A business's category and its label: the seven labels are the console's English, read by the
 * console's two screens alone, the new tenant's form and a tenant's detail. The signup names
 * its categories in signup.php's own words and no admin page shows one, so the labels never
 * reach a tenant's user and stay out of the language files. That the console alone reads one is
 * SharedHomesTest's pin; here the seven are held distinct.
 */

it('labels each of the seven categories the tenants table allows, distinctly', function () {
    $labels = array_map(fn (BusinessCategory $category): string => $category->label(), BusinessCategory::cases());

    expect($labels)->toHaveCount(7)
        ->and(array_unique($labels))->toHaveCount(7);
});
