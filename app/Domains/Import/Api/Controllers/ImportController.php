<?php

namespace App\Domains\Import\Api\Controllers;

use App\Domains\Import\Services\InitiateImport;
use App\Http\Controllers\ApiController;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends ApiController
{
    public function __construct()
    {
        $this->middleware('abilities:write')->only(['store', 'cancel']);
        $this->middleware('abilities:read')->only(['index', 'show', 'errors']);

        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $imports = ImportJob::where('account_id', $request->user()->account_id)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($this->getLimitPerPage());

        return response()->json([
            'data' => ImportJobResource::collection($imports),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'per_page'     => $imports->perPage(),
                'total'        => $imports->total(),
                'last_page'    => $imports->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'     => 'required|file|mimes:csv|max:10240',
            'vault_id' => 'required|uuid|exists:vaults,id',
        ]);

        $importJob = (new InitiateImport)->execute([
            'account_id' => $request->user()->account_id,
            'author_id'  => $request->user()->id,
            'vault_id'   => $request->input('vault_id'),
            'file'       => $request->file('file'),
        ]);

        return (new ImportJobResource($importJob))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $importId): JsonResponse
    {
        $importJob = ImportJob::where('account_id', $request->user()->account_id)
            ->findOrFail($importId);

        return new JsonResponse(['data' => new ImportJobResource($importJob)]);
    }

    public function cancel(Request $request, string $importId): JsonResponse
    {
        $importJob = ImportJob::where('account_id', $request->user()->account_id)
            ->findOrFail($importId);

        if (! $importJob->isCancellable()) {
            return $this->setHTTPStatusCode(422)
                ->respondWithError('Import cannot be cancelled in its current state.');
        }

        $importJob->update([
            'status'       => ImportJob::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);

        return new JsonResponse(['data' => new ImportJobResource($importJob->fresh())]);
    }

    public function errors(Request $request, string $importId): StreamedResponse
    {
        $importJob = ImportJob::where('account_id', $request->user()->account_id)
            ->findOrFail($importId);

        $errors = $importJob->errors ?? [];

        return response()->streamDownload(function () use ($errors) {
            $handle = fopen('php://output', 'w');

            $headers = ! empty($errors)
                ? array_merge(array_keys($errors[0]['data']), ['error'])
                : ['error'];

            fputcsv($handle, $headers);

            foreach ($errors as $entry) {
                fputcsv($handle, array_merge(array_values($entry['data']), [$entry['message']]));
            }

            fclose($handle);
        }, "{$importJob->filename}-errors.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }
}
