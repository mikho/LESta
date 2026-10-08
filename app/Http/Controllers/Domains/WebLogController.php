<?php

namespace App\Http\Controllers\Domains;

use App\Actions\Files\ObserveWebLog;
use App\Http\Controllers\Controller;
use App\Models\ProvisioningOperation;
use App\Models\UsageSnapshot;
use App\Models\WebDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A web domain's traffic figures and raw logs. The page reads the node over the same fast lane
 * as the file manager (see ObserveWebLog), and polls the file manager's own operation status
 * endpoint for the result.
 */
class WebLogController extends Controller
{
    public function index(WebDomain $webDomain): Response
    {
        Gate::authorize('view', $webDomain);

        return Inertia::render('domains/logs', [
            'webDomain' => ['uuid' => $webDomain->uuid, 'domain' => $webDomain->domain],
            'history' => $this->history($webDomain),
        ]);
    }

    public function observe(Request $request, WebDomain $webDomain): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ObserveWebLog::KINDS)],
            'mode' => ['required', Rule::in(ObserveWebLog::MODES)],
            'lines' => ['nullable', 'integer', 'between:1,500'],
        ]);

        $operation = app(ObserveWebLog::class)->handle($request->user(), $webDomain, $data['kind'], $data['mode'], (int) ($data['lines'] ?? 100));

        return response()->json(['operation_id' => $operation->id], 202);
    }

    /**
     * Streams a finished download operation's log content as a file.
     */
    public function download(WebDomain $webDomain, ProvisioningOperation $operation): StreamedResponse
    {
        Gate::authorize('view', $webDomain);

        $data = $operation->data;

        if (
            $operation->provisionable_type !== $webDomain->getMorphClass()
            || $operation->provisionable_id !== $webDomain->getKey()
            || $operation->capability !== 'files.manager.v1'
            || ! is_array($data)
            || ! isset($data['content_base64'], $data['kind'])
            || ! in_array($data['kind'], ObserveWebLog::KINDS, true)
        ) {
            throw new NotFoundHttpException;
        }

        $content = base64_decode((string) $data['content_base64'], true);

        abort_if($content === false, 404);

        $filename = $webDomain->domain.'-'.$data['kind'].'.log';

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * The domain's recorded requests and bandwidth per day for the last 30 days, from the usage
     * snapshots the node already collects.
     *
     * @return list<array{date: string, requests: int, bytes_sent: int}>
     */
    private function history(WebDomain $webDomain): array
    {
        $days = UsageSnapshot::query()
            ->where('snapshotable_type', $webDomain->getMorphClass())
            ->where('snapshotable_id', $webDomain->getKey())
            ->where('collected_at', '>=', now()->subDays(30)->startOfDay())
            ->orderBy('collected_at')
            ->get(['request_count', 'bytes_sent', 'collected_at'])
            ->groupBy(fn (UsageSnapshot $snapshot): string => $snapshot->collected_at->toDateString());

        return array_values($days->map(fn ($rows, string $date): array => [
            'date' => $date,
            'requests' => (int) $rows->sum('request_count'),
            'bytes_sent' => (int) $rows->sum('bytes_sent'),
        ])->all());
    }
}
