<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\ServiceFeeSetting;
use Illuminate\Support\Facades\Cache;

class ServiceFeeCalculator
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Calculate the service fee rate for an operator based on their revenue
     */
    public function calculateFeeRate(Operator $operator, float $operatorRevenue): float
    {
        // Priority 1: Check for operator-specific fee
        $operatorFee = ServiceFeeSetting::query()
            ->active()
            ->operatorSpecific()
            ->forOperator($operator)
            ->first();

        if ($operatorFee) {
            return (float) $operatorFee->fee_rate;
        }

        // Priority 2: Check for revenue-based tier
        $revenueTier = ServiceFeeSetting::query()
            ->active()
            ->revenueTier()
            ->forRevenue($operatorRevenue)
            ->first();

        if ($revenueTier) {
            return (float) $revenueTier->fee_rate;
        }

        // Priority 3: Fall back to site-wide default
        $siteWide = ServiceFeeSetting::query()
            ->active()
            ->siteWide()
            ->first();

        if ($siteWide) {
            return (float) $siteWide->fee_rate;
        }

        // Final fallback: Use config default
        return (float) config('portal.ewallet_fee_rate', 0.05);
    }

    /**
     * Calculate the service fee amount for a given revenue amount
     */
    public function calculateFeeAmount(Operator $operator, float $revenue): float
    {
        $feeRate = $this->calculateFeeRate($operator, $revenue);
        return round($revenue * $feeRate, 2);
    }

    /**
     * Calculate the net amount after service fee
     */
    public function calculateNetAmount(Operator $operator, float $revenue): float
    {
        $feeAmount = $this->calculateFeeAmount($operator, $revenue);
        return round($revenue - $feeAmount, 2);
    }

    /**
     * Get the fee calculation details for display
     */
    public function getFeeDetails(Operator $operator, float $revenue): array
    {
        $feeRate = $this->calculateFeeRate($operator, $revenue);
        $feeAmount = round($revenue * $feeRate, 2);
        $netAmount = round($revenue - $feeAmount, 2);

        // Determine which fee type was applied
        $feeType = 'default'; // fallback
        $feeDescription = 'Default fee rate';

        $operatorFee = ServiceFeeSetting::query()
            ->active()
            ->operatorSpecific()
            ->forOperator($operator)
            ->first();

        if ($operatorFee) {
            $feeType = 'operator_specific';
            $feeDescription = $operatorFee->description ?? "Operator-specific rate: {$operatorFee->getFeeRateAsPercentage()}";
        } else {
            $revenueTier = ServiceFeeSetting::query()
                ->active()
                ->revenueTier()
                ->forRevenue($revenue)
                ->first();

            if ($revenueTier) {
                $feeType = 'revenue_tier';
                $feeDescription = $revenueTier->description ?? "Revenue tier rate: {$revenueTier->getFeeRateAsPercentage()}";
            } else {
                $siteWide = ServiceFeeSetting::query()
                    ->active()
                    ->siteWide()
                    ->first();

                if ($siteWide) {
                    $feeType = 'site_wide';
                    $feeDescription = $siteWide->description ?? "Site-wide rate: {$siteWide->getFeeRateAsPercentage()}";
                }
            }
        }

        return [
            'revenue' => $revenue,
            'fee_rate' => $feeRate,
            'fee_rate_percentage' => ($feeRate * 100) . '%',
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'fee_type' => $feeType,
            'fee_description' => $feeDescription,
        ];
    }

    /**
     * Clear the cache for an operator
     */
    public function clearOperatorCache(Operator $operator): void
    {
        // Clear all cache entries for this operator
        $pattern = "service_fee_rate_{$operator->id}_*";
        // Note: In a real implementation, you might want to use a cache tag system
        // For now, we'll just let the cache expire naturally
    }

    /**
     * Get all active fee settings for admin display
     */
    public function getAllFeeSettings(): array
    {
        return [
            'site_wide' => ServiceFeeSetting::query()
                ->active()
                ->siteWide()
                ->with('operator')
                ->get(),

            'operator_specific' => ServiceFeeSetting::query()
                ->active()
                ->operatorSpecific()
                ->with('operator')
                ->get(),

            'revenue_tiers' => ServiceFeeSetting::query()
                ->active()
                ->revenueTier()
                ->orderBy('revenue_threshold_min')
                ->get(),
        ];
    }
}
