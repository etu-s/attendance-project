<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Http\Requests\CreateExportRequest;
use App\Modules\Reports\Http\Resources\ReportExportResource;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A user's own report exports. Someone else's export answers 404, so ids
 * reveal nothing.
 */
final class ReportExportController extends Controller
{
    public function __construct(
        private readonly ReportExportService $exports,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('reports.export');
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ams.pagination.max_per_page')]]);
        /** @var User $user */
        $user = $request->user();

        return ReportExportResource::collection(
            $this->exports->mine($user, (int) $request->input('per_page', config('ams.pagination.default_per_page'))),
        );
    }

    public function store(CreateExportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var ReportKind $kind */
        $kind = $request->kind();
        $export = $this->exports->request($user, $kind, $request->exportFormat(), $request->params());

        return (new ReportExportResource($export->refresh()))->response()->setStatusCode(202);
    }

    public function show(Request $request, ReportExport $reportExport): ReportExportResource
    {
        $this->own($request, $reportExport);

        return new ReportExportResource($reportExport);
    }

    public function download(Request $request, ReportExport $reportExport): StreamedResponse
    {
        $this->own($request, $reportExport);

        if ($this->exports->isExpired($reportExport)) {
            throw new BusinessRuleException('This export has expired. Request a new one.', 'export_expired', 410);
        }
        if (! $reportExport->isDownloadable()) {
            throw new BusinessRuleException('This export is not ready.', 'export_not_ready', 409);
        }

        return Storage::disk('local')->download((string) $reportExport->path, $reportExport->file_name, [
            'Content-Type' => $reportExport->format->mimeType(),
        ]);
    }

    private function own(Request $request, ReportExport $export): void
    {
        $this->authorize('reports.export');

        if ($export->requested_by !== $request->user()?->id) {
            throw new NotFoundHttpException;
        }
    }
}
