<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    public function show(Request $request, GamificationService $gamification): JsonResponse
    {
        return response()->json($gamification->summary($request->user()->id));
    }
}
