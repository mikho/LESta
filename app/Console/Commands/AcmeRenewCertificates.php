<?php

namespace App\Console\Commands;

use App\Enums\SslMode;
use App\Jobs\IssueAcmeCertificate;
use App\Models\WebDomain;
use Illuminate\Console\Command;

class AcmeRenewCertificates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'acme:renew-certificates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch IssueAcmeCertificate for every lets_encrypt domain whose certificate expires within the configured renewal window.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expiresBefore = now()->addDays((int) config('acme.renew_within_days', 30));

        WebDomain::query()
            ->where('ssl_mode', SslMode::LetsEncrypt)
            ->where(function ($query) use ($expiresBefore): void {
                $query->whereNull('certificate_expires_at')
                    ->orWhere('certificate_expires_at', '<=', $expiresBefore);
            })
            ->each(function (WebDomain $webDomain): void {
                IssueAcmeCertificate::dispatch($webDomain);
            });

        return self::SUCCESS;
    }
}
