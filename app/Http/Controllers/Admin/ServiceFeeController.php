<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\ServiceFeeSetting;
use App\Services\ServiceFeeCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceFeeController extends Controller
{
    public function __construct(
        private readonly ServiceFeeCalculator $serviceFeeCalculator
    ) {}

    public function index(): Response
    {
        $feeSettings = $this->serviceFeeCalculator->getAllFeeSettings();
        
        return Inertia::render('Admin/ServiceFees/Index', [
            'feeSettings' => $feeSettings,
            'operators' => Operator::query()
                ->where('status', Operator::STATUS_APPROVED)
                ->orderBy('business_name')
                ->get(['id', 'business_name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/ServiceFees/Create', [
            'operators' => Operator::query()
                ->where('status', Operator::STATUS_APPROVED)
                ->orderBy('business_name')
                ->get(['id', 'business_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([
                ServiceFeeSetting::TYPE_SITE_WIDE,
                ServiceFeeSetting::TYPE_OPERATOR_SPECIFIC,
                ServiceFeeSetting::TYPE_REVENUE_TIER,
            ])],
            'operator_id' => ['required_if:type,operator_specific', 'exists:operators,id'],
            'fee_rate' => ['required', 'numeric', 'min:0', 'max:1'], // 0% to 100%
            'revenue_threshold_min' => ['required_if:type,revenue_tier', 'nullable', 'numeric', 'min:0'],
            'revenue_threshold_max' => ['nullable', 'numeric', 'min:0', 'gt:revenue_threshold_min'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        // Validate revenue tier thresholds
        if ($validated['type'] === ServiceFeeSetting::TYPE_REVENUE_TIER) {
            if (!$validated['revenue_threshold_min']) {
                return back()
                    ->withInput()
                    ->withErrors(['revenue_threshold_min' => 'Revenue threshold minimum is required for revenue tiers.']);
            }

            // Check for overlapping revenue tiers
            $existingTiers = ServiceFeeSetting::query()
                ->active()
                ->revenueTier()
                ->get();

            foreach ($existingTiers as $tier) {
                if ($this->revenueRangesOverlap(
                    $validated['revenue_threshold_min'],
                    $validated['revenue_threshold_max'] ?? null,
                    $tier->revenue_threshold_min,
                    $tier->revenue_threshold_max
                )) {
                    return back()
                        ->withInput()
                        ->withErrors(['revenue_threshold_min' => 'This revenue range overlaps with an existing tier.']);
                }
            }
        }

        // Deactivate existing site-wide fees if creating a new one
        if ($validated['type'] === ServiceFeeSetting::TYPE_SITE_WIDE) {
            ServiceFeeSetting::query()
                ->siteWide()
                ->active()
                ->update(['is_active' => false]);
        }

        // Deactivate existing operator-specific fees if creating a new one for the same operator
        if ($validated['type'] === ServiceFeeSetting::TYPE_OPERATOR_SPECIFIC) {
            ServiceFeeSetting::query()
                ->operatorSpecific()
                ->forOperator(Operator::findOrFail($validated['operator_id']))
                ->active()
                ->update(['is_active' => false]);
        }

        ServiceFeeSetting::create($validated);

        return redirect()
            ->route('admin.service-fees.index')
            ->with('success', 'Service fee setting created successfully.');
    }

    public function edit(ServiceFeeSetting $serviceFee): Response
    {
        return Inertia::render('Admin/ServiceFees/Edit', [
            'serviceFee' => $serviceFee->load('operator:id,business_name'),
            'operators' => Operator::query()
                ->where('status', Operator::STATUS_APPROVED)
                ->orderBy('business_name')
                ->get(['id', 'business_name']),
        ]);
    }

    public function update(Request $request, ServiceFeeSetting $serviceFee): RedirectResponse
    {
        $validated = $request->validate([
            'fee_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $serviceFee->update($validated);

        return redirect()
            ->route('admin.service-fees.index')
            ->with('success', 'Service fee setting updated successfully.');
    }

    public function activate(ServiceFeeSetting $serviceFee): RedirectResponse
    {
        // Deactivate other fees of the same type first
        if ($serviceFee->type === ServiceFeeSetting::TYPE_SITE_WIDE) {
            ServiceFeeSetting::query()
                ->siteWide()
                ->active()
                ->where('id', '!=', $serviceFee->id)
                ->update(['is_active' => false]);
        } elseif ($serviceFee->type === ServiceFeeSetting::TYPE_OPERATOR_SPECIFIC) {
            ServiceFeeSetting::query()
                ->operatorSpecific()
                ->active()
                ->where('id', '!=', $serviceFee->id)
                ->where('operator_id', $serviceFee->operator_id)
                ->update(['is_active' => false]);
        }

        $serviceFee->update(['is_active' => true]);

        return redirect()
            ->route('admin.service-fees.index')
            ->with('success', 'Service fee setting activated.');
    }

    public function deactivate(ServiceFeeSetting $serviceFee): RedirectResponse
    {
        $serviceFee->update(['is_active' => false]);

        return redirect()
            ->route('admin.service-fees.index')
            ->with('success', 'Service fee setting deactivated.');
    }

    public function destroy(ServiceFeeSetting $serviceFee): RedirectResponse
    {
        $serviceFee->delete();

        return redirect()
            ->route('admin.service-fees.index')
            ->with('success', 'Service fee setting deleted.');
    }

    private function revenueRangesOverlap(
        ?float $min1,
        ?float $max1,
        ?float $min2,
        ?float $max2
    ): bool {
        if ($max1 === null && $max2 === null) {
            return true; // Both are open-ended ranges
        }

        if ($max1 === null) {
            return $min1 <= $max2;
        }

        if ($max2 === null) {
            return $min2 <= $max1;
        }

        return !($max1 < $min2 || $max2 < $min1);
    }
}
