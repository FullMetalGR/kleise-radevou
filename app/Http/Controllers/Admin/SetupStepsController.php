<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Billing\Feature;
use App\Http\AdminLines;
use App\Http\AdminSession;
use App\Http\FeatureGate;
use App\Http\Requests\ServiceRequest;
use App\Http\Requests\SettingsRequest;
use App\Http\Requests\SetupProfileRequest;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Db\ServiceRecords;
use App\Infrastructure\Db\SettingsRecords;
use App\Infrastructure\Db\SetupRecords;
use App\Infrastructure\Db\StaffRecords;
use App\Infrastructure\Map\GoogleMapsKey;
use App\Jobs\PrepareMap;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The writes of the setup wizard's Business profile, Services and Team steps. Each is the full
 * screen's own write under the wizard's door: the same body, the same rules and refusals, the same
 * store and the same flash, the owner's alone and behind the subscription gate, since each
 * configures the business. What differs is the answer: a redirect back, Home where the request
 * names no page, never the new row's own page, so the wizard stays open and Home's props arrive
 * re-read.
 */
final readonly class SetupStepsController
{
    public function __construct(
        private CurrentTenant $current,
        private SettingsRecords $settings,
        private SetupRecords $setup,
        private ServiceRecords $services,
        private StaffRecords $staff,
        private FeatureGate $gate,
        private GoogleMapsKey $mapsKey,
    ) {}

    /**
     * About, the address and Phone, and nothing else; an address change prepares the map as the
     * Settings save does. A save, an empty one included, marks the step done.
     */
    public function profile(SetupProfileRequest $request): RedirectResponse
    {
        $tenant = $this->current->get();

        // The save has committed when updateProfile() answers, so the map's job is queued after it.
        if ($this->settings->updateProfile($tenant, $request->profile(), $this->mapsKey->isSet())) {
            PrepareMap::dispatch($tenant->id);
        }

        $this->setup->markProfileSaved($tenant);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => SettingsRequest::line(SettingsController::SAVED)]);

        return $this->back($request);
    }

    /**
     * A new service, the Services screen's create: every field it does not name takes that
     * screen's blank value, sent by the step, and the implicit member performs it where they are
     * the only staff row, as there.
     */
    public function service(ServiceRequest $request): RedirectResponse
    {
        $this->services->insert($this->current->get(), $request->patch());
        $request->session()->flash('flash', ['kind' => 'success', 'message' => AdminLines::line(ServiceController::CREATED)]);

        return $this->back($request);
    }

    /**
     * A new member, the Staff screen's create, with the services they perform: refused as there
     * where the plan holds no staff feature, before the body is read.
     */
    public function member(): RedirectResponse
    {
        $this->gate->require(Feature::Staff);
        $request = app(StaffRequest::class);
        $this->staff->insert($this->current->get(), $request->patch(), $request->serviceIds() ?? []);
        $request->session()->flash('flash', ['kind' => 'success', 'message' => StaffRequest::line(StaffController::CREATED)]);

        return $this->back($request);
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->back(fallback: AdminSession::at($request, AdminSession::PREFIX));
    }
}
