<?php

namespace App\Http\Controllers\Registry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registry\LocationRequest;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Locations are managed from the organization page (dialogs), so every action
 * redirects back to it.
 */
class LocationController extends Controller
{
    public function store(LocationRequest $request, Organization $organization): RedirectResponse
    {
        $organization->locations()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sede aggiunta.')]);

        return back();
    }

    public function update(LocationRequest $request, Organization $organization, Location $location): RedirectResponse
    {
        $location->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sede aggiornata.')]);

        return back();
    }

    public function destroy(Organization $organization, Location $location): RedirectResponse
    {
        $this->authorize('update', $organization);

        $location->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sede eliminata.')]);

        return back();
    }
}
