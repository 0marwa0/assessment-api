<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\CertificateIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Certificates (backlog R9). Learners list their own; managers may list
 * anyone's with ?user_id=. A certificate's code also opens a small public
 * verification record (no login), so a shared link can be checked.
 */
class CertificateController extends Controller
{
    public function index(Request $request, CertificateIssuer $issuer): JsonResponse
    {
        $user = $request->user();
        $userId = $user->isManager() && $request->filled('user_id') ? $request->integer('user_id') : $user->id;

        $issuer->backfillFor($userId);

        $certificates = Certificate::query()
            ->with(['assessment:id,title', 'user:id,name'])
            ->where('user_id', $userId)
            ->latest('issued_at')
            ->get();

        return response()->json(['data' => $certificates->map(fn (Certificate $c) => $this->present($c))->values()]);
    }

    public function show(string $code): JsonResponse
    {
        $certificate = Certificate::query()
            ->with(['assessment:id,title', 'user:id,name'])
            ->where('code', strtoupper($code))
            ->firstOrFail();

        $data = $this->present($certificate);
        unset($data['user_id']); // public page: name only

        return response()->json(['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Certificate $certificate): array
    {
        return [
            'code' => $certificate->code,
            'user_id' => $certificate->user_id,
            'user_name' => $certificate->user?->name,
            'assessment_id' => $certificate->assessment_id,
            'assessment_title' => $certificate->assessment?->title,
            'tier' => $certificate->tier,
            'score_pct' => $certificate->score_pct,
            'issued_at' => $certificate->issued_at?->toISOString(),
            'issuer' => config('app.name'),
        ];
    }
}
