import { usePage } from '@inertiajs/react';
import LegalLayout, { Controller } from '@/layouts/legal-layout';

export default function Terms() {
    const { legal } = usePage().props;

    return (
        <LegalLayout title="Terms of service">
            <Controller />

            <h2>The service</h2>
            <p>
                This panel lets you manage hosting resources such as domains,
                DNS, mail, databases, files and backups. Accounts are created by
                administrators and by invitation.
            </p>

            <h2>Who may use it</h2>
            <p>
                You must be at least 18 years old and able to enter into a
                binding agreement. You are responsible for keeping your
                credentials secret and for what happens under your account.
            </p>

            <h2>Acceptable use</h2>
            <ul>
                <li>No unlawful content, spam, malware or phishing.</li>
                <li>
                    No attempts to reach other customers&apos; data or to
                    disrupt the service.
                </li>
                <li>Respect the limits of your package.</li>
            </ul>
            <p>
                We may suspend resources that break these rules or put the
                service at risk.
            </p>

            <h2>Your content and backups</h2>
            <p>
                You own your content. You are responsible for keeping your own
                copies; backups are a convenience, not a guarantee.
            </p>

            <h2>Availability and liability</h2>
            <p>
                The service is provided as is. To the extent the law allows, we
                are not liable for indirect loss or loss of data.
            </p>

            <h2>Payment and refunds</h2>
            <p>
                Billing is handled outside this panel, under your agreement with
                your hosting provider.
            </p>

            <h2>Governing law</h2>
            <p>{legal.governing_law ?? 'Governing law: not configured.'}</p>
        </LegalLayout>
    );
}
