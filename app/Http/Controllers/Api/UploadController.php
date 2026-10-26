<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function store(StoreUploadRequest $request): JsonResponse
    {
        $type = $request->validated('type');
        $path = $request->file('file')->store("materials/{$type}s", 'public');

        return response()->json([
            'data' => [
                'type' => $type,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ], 201);
    }
}
