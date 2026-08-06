<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaterialRequest;
use App\Http\Requests\UpdateMaterialRequest;
use App\Http\Resources\MaterialResource;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $materials = Material::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search').'%';

                $query->where(fn ($query) => $query->where('title', 'like', $search)
                    ->orWhere('summary', 'like', $search));
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return MaterialResource::collection($materials)
            ->additional([
                'meta' => [
                    'status_counts' => $this->statusCounts(),
                ],
            ]);
    }

    public function store(StoreMaterialRequest $request): MaterialResource
    {
        $material = Material::create([
            ...$request->validated(),
            'status' => $request->validated('status', 'draft'),
        ]);

        return (new MaterialResource($material))
            ->additional(['message' => 'Material created successfully.']);
    }

    public function show(Material $material): MaterialResource
    {
        return new MaterialResource($material);
    }

    public function update(UpdateMaterialRequest $request, Material $material): MaterialResource
    {
        $material->fill($request->validated());
        $material->save();

        return (new MaterialResource($material))
            ->additional(['message' => 'Material updated successfully.']);
    }

    public function destroy(Material $material): JsonResponse
    {
        $material->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = Material::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return array_merge([
            'draft' => 0,
            'published' => 0,
            'archived' => 0,
        ], $counts);
    }
}
