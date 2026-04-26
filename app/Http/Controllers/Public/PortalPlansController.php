<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\PortalPlanViewData;
use Illuminate\Http\JsonResponse;

class PortalPlansController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'plans' => PortalPlanViewData::collection(
                    Plan::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('price')
                        ->get()
                ),
            ],
        ]);
    }
}
