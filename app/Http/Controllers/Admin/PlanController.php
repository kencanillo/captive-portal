<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Plan::class);

        return Inertia::render('Admin/Plans', [
            'plans' => Plan::query()
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get(),
        ]);
    }

    public function store(CreatePlanRequest $request)
    {
        $this->authorize('create', Plan::class);
        Plan::create([
            ...$request->validated(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        return redirect()->route('admin.plans.index')->with('success', 'Plan created.');
    }

    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $this->authorize('update', $plan);
        $plan->update([
            ...$request->validated(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        return redirect()->route('admin.plans.index')->with('success', 'Plan updated.');
    }

    public function destroy(Plan $plan)
    {
        $this->authorize('delete', $plan);
        $plan->delete();

        return redirect()->route('admin.plans.index')->with('success', 'Plan deleted.');
    }
}
