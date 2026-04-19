<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PortalPlansController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'plans' => Plan::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('price')
                    ->get()
                    ->map(fn (Plan $plan) => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'price' => $plan->price,
                        'duration_minutes' => $plan->duration_minutes,
                        'data_limit_mb' => $plan->data_limit_mb,
                        'download_speed_kbps' => $plan->download_speed_kbps,
                        'upload_speed_kbps' => $plan->upload_speed_kbps,
                        'is_active' => $plan->is_active,
                    ]),
            ],
        ]);
    }
}
