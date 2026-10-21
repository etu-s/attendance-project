<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Requests\PunchSourceRequest;
use App\Modules\Attendance\Http\Resources\PunchSourceResource;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Attendance\Services\PunchSourceService;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Devices and integrations (punch-sources.manage, Tenant Admin by default).
 * The key and signing secret are in the create/rotate responses only.
 */
final class PunchSourceController extends Controller
{
    public function __construct(
        private readonly PunchSourceService $sources,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('punch-sources.manage');

        return PunchSourceResource::collection(PunchSource::query()->orderBy('name')->orderBy('id')->get());
    }

    public function store(PunchSourceRequest $request): JsonResponse
    {
        /** @var array{name: string, kind: string, ip_allowlist?: list<string>|null, signed?: bool} $data */
        $data = $request->validated();

        return $this->withSecrets(...$this->sources->create($data))->setStatusCode(201);
    }

    public function show(PunchSource $punchSource): PunchSourceResource
    {
        $this->authorize('punch-sources.manage');

        return new PunchSourceResource($punchSource);
    }

    public function update(PunchSourceRequest $request, PunchSource $punchSource): PunchSourceResource
    {
        /** @var array{name?: string, kind?: string, ip_allowlist?: list<string>|null, is_active?: bool} $data */
        $data = $request->validated();

        return new PunchSourceResource($this->sources->update($punchSource, $data));
    }

    public function rotate(PunchSource $punchSource): JsonResponse
    {
        $this->authorize('punch-sources.manage');

        return $this->withSecrets(...$this->sources->rotate($punchSource));
    }

    public function destroy(PunchSource $punchSource): Response
    {
        $this->authorize('punch-sources.manage');
        $this->sources->delete($punchSource);

        return response()->noContent();
    }

    private function withSecrets(PunchSource $source, string $key, ?string $signingSecret): JsonResponse
    {
        return response()->json([
            'data' => new PunchSourceResource($source),
            'key' => $key,
            'signing_secret' => $signingSecret,
        ]);
    }
}
