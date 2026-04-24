<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;

class PortalPlanViewData
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function collection(Collection $plans): array
    {
        return $plans
            ->map(fn (Plan $plan): array => self::fromModel($plan))
            ->values()
            ->all();
    }

    public static function fromModel(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'base_price' => $plan->price,
            'processing_fee_rate' => $plan->processing_fee_rate,
            'processing_fee_amount' => $plan->processing_fee_amount,
            'customer_price' => $plan->customer_price,
            'net_amount' => $plan->net_amount,
            'duration_minutes' => $plan->duration_minutes,
            'data_limit_mb' => $plan->data_limit_mb,
            'download_speed_kbps' => $plan->download_speed_kbps,
            'upload_speed_kbps' => $plan->upload_speed_kbps,
            'is_active' => $plan->is_active,
        ];
    }
}
