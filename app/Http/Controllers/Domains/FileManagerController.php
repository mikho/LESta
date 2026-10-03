<?php

namespace App\Http\Controllers\Domains;

use App\Actions\Files\CreateFile;
use App\Actions\Files\DeleteFile;
use App\Actions\Files\ObserveFiles;
use App\Actions\Files\UpdateFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Files\DestroyFileRequest;
use App\Http\Requests\Files\StoreFileRequest;
use App\Http\Requests\Files\UpdateFileRequest;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FileManagerController extends Controller
{
    /**
     * The file manager's own single stateful page -- everything past this point (listing,
     * reading, creating, updating, deleting) is a plain JSON endpoint that page's own React code
     * calls directly, never a further Inertia::render, since every real operation here is
     * dispatched then completed asynchronously over files.manager.v1's own dedicated fast lane.
     */
    public function index(WebDomain $webDomain): Response
    {
        Gate::authorize('view', $webDomain);

        return Inertia::render('files/index', [
            'webDomain' => [
                'uuid' => $webDomain->uuid,
                'domain' => $webDomain->domain,
            ],
        ]);
    }

    /**
     * Dispatches a real Observe operation (lists a directory or reads a file, whichever the
     * given path resolves to) and immediately returns its own operation id for the page to poll
     * via operationStatus() -- this endpoint itself never waits for the real node.
     */
    public function observe(Request $request, WebDomain $webDomain): JsonResponse
    {
        $path = (string) $request->string('path');

        $operation = app(ObserveFiles::class)->handle($request->user(), $webDomain, $path);

        return response()->json(['operation_id' => $operation->id], 202);
    }

    public function store(StoreFileRequest $request, WebDomain $webDomain): JsonResponse
    {
        $operation = app(CreateFile::class)->handle(
            $request->user(),
            $webDomain,
            (string) $request->validated('path', ''),
            (bool) $request->validated('is_directory', false),
            $request->validated('content_base64'),
        );

        return response()->json(['operation_id' => $operation->id], 202);
    }

    public function update(UpdateFileRequest $request, WebDomain $webDomain): JsonResponse
    {
        $operation = app(UpdateFile::class)->handle(
            $request->user(),
            $webDomain,
            (string) $request->validated('path'),
            $request->validated('new_path'),
            $request->validated('content_base64'),
        );

        return response()->json(['operation_id' => $operation->id], 202);
    }

    public function destroy(DestroyFileRequest $request, WebDomain $webDomain): JsonResponse
    {
        $operation = app(DeleteFile::class)->handle(
            $request->user(),
            $webDomain,
            (string) $request->validated('path'),
            (bool) $request->validated('recursive', false),
        );

        return response()->json(['operation_id' => $operation->id], 202);
    }

    /**
     * Polled by the file manager page every second or so while an operation is pending --
     * reports the exact same terminal shape every operation reaches (status, data, errors)
     * regardless of which verb it was, so the frontend's own polling code stays identical across
     * observe/create/update/delete.
     */
    public function operationStatus(WebDomain $webDomain, ProvisioningOperation $operation): JsonResponse
    {
        Gate::authorize('view', $webDomain);

        if ($operation->provisionable_type !== $webDomain->getMorphClass() || $operation->provisionable_id !== $webDomain->getKey()) {
            throw new NotFoundHttpException;
        }

        return response()->json([
            'status' => $operation->status->value,
            'data' => $operation->data,
            'errors' => $operation->errors,
        ]);
    }
}
