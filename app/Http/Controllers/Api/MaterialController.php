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

        // Managers' Material Bank shows "used in n roadmaps" and "read by n brokers" (MG7).
        if ($request->user()?->isManager()) {
            $ids = $materials->getCollection()->pluck('id');
            $usedIn = \App\Models\RoadmapNode::query()
                ->whereIn('material_id', $ids)
                ->selectRaw('material_id, count(distinct roadmap_id) as n')
                ->groupBy('material_id')
                ->pluck('n', 'material_id');
            $readBy = \App\Models\RoadmapNodeProgress::query()
                ->join('roadmap_nodes', 'roadmap_nodes.id', '=', 'roadmap_node_progress.roadmap_node_id')
                ->whereIn('roadmap_nodes.material_id', $ids)
                ->where('roadmap_node_progress.status', 'done')
                ->selectRaw('roadmap_nodes.material_id as material_id, count(distinct roadmap_node_progress.user_id) as n')
                ->groupBy('roadmap_nodes.material_id')
                ->pluck('n', 'material_id');

            foreach ($materials->getCollection() as $material) {
                $material->setAttribute('used_in_count', (int) ($usedIn[$material->id] ?? 0));
                $material->setAttribute('read_by_count', (int) ($readBy[$material->id] ?? 0));
            }
        }

        return MaterialResource::collection($materials)
            ->additional([
                'meta' => [
                    'status_counts' => $this->statusCounts(),
                ],
            ]);
    }

    public function store(StoreMaterialRequest $request): MaterialResource
    {
        $data = $request->validated();
        $material = new Material([
            ...$data,
            'stages' => $data['stages'] ?? [],
            'tags' => $data['tags'] ?? [],
            'status' => $data['status'] ?? 'draft',
        ]);
        $this->fillDerivedSummary($material);
        $material->save();

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
        $this->fillDerivedSummary($material);
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
     * MG10: an empty summary is taken from the page's first paragraph.
     */
    private function fillDerivedSummary(Material $material): void
    {
        if (filled($material->summary)) {
            return;
        }

        $summary = '';
        if (filled($material->content) && preg_match('#<p>(.*?)</p>#s', (string) $material->content, $match)) {
            $summary = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($summary === '') {
            $summary = $material->plainText();
        }
        if ($summary === '' && ! empty($material->attachments)) {
            $summary = 'Files: '.collect($material->attachments)->pluck('name')->filter()->implode(', ');
        }

        $material->summary = mb_strlen($summary) > 240 ? rtrim(mb_substr($summary, 0, 237)).'...' : $summary;
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
