import { Link, usePage } from '@inertiajs/react';
import LegalLayout, { Controller } from '@/layouts/legal-layout';
import { edit } from '@/routes/profile';

export default function Privacy() {
    const { legal } = usePage().props;

    return (
        <LegalLayout title="Privacy policy">
            <Controller />

            <h2>What we collect and why</h2>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Data</th>
                        <th scope="col">Purpose</th>
                        <th scope="col">Basis</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            Name, email address, password (stored hashed),
                            two-factor and passkey credentials
                        </td>
                        <td>Creating and securing your sign-in</td>
                        <td>Contract</td>
                    </tr>
                    <tr>
                        <td>Time and IP address of your latest sign-ins</td>
                        <td>
                            Showing you recent activity so you can spot misuse
                        </td>
                        <td>Legitimate interest (security)</td>
                    </tr>
                    <tr>
                        <td>
                            Audit records: who did what, when, from which IP
                            address and browser
                        </td>
                        <td>Security, accountability and fault finding</td>
                        <td>Legitimate interest (security)</td>
                    </tr>
                    <tr>
                        <td>
                            Hosting data you create: domains, DNS, mail,
                            databases, files, backups, usage figures
                        </td>
                        <td>Providing the hosting service</td>
                        <td>Contract</td>
                    </tr>
                    <tr>
                        <td>Session cookie and your cookie choice</td>
                        <td>
                            Keeping you signed in and remembering your choice
                        </td>
                        <td>Strictly necessary</td>
                    </tr>
                    <tr>
                        <td>Theme and sidebar preference</td>
                        <td>Remembering your display choices</td>
                        <td>Consent</td>
                    </tr>
                </tbody>
            </table>
            <p>
                We do not collect anything else. We do not use analytics,
                advertising or tracking services, and the panel loads no
                third-party scripts, fonts or images.
            </p>

            <h2>Sharing</h2>
            <p>
                Data is processed on the servers that run your hosting. Your
                hosting provider (the reseller or administrator of your account)
                can see your account data. We do not sell personal data.
                Transactional emails (sign-in verification, password reset,
                invitations) are sent through the mail service configured for
                this panel.
            </p>

            <h2>How long we keep it</h2>
            <p>
                Account data is kept while your account exists and deleted when
                you delete it. Audit records are kept for security and tamper
                evidence and can remain after deletion; they then identify you
                only by an internal number. Backups expire according to the
                retention of your package.
            </p>

            <h2>Your rights</h2>
            <p>
                You can ask for access to, correction or deletion of your data,
                object to processing, and complain to your data protection
                authority. You can delete your account yourself on your{' '}
                <Link href={edit()} className="underline underline-offset-4">
                    profile page
                </Link>
                , or write to{' '}
                {legal.privacy_email ? (
                    <a
                        href={`mailto:${legal.privacy_email}`}
                        className="underline underline-offset-4"
                    >
                        {legal.privacy_email}
                    </a>
                ) : (
                    'the contact address (not configured)'
                )}{' '}
                to make a request.
            </p>

            <h2>Children</h2>
            <p>
                The panel is for adults only. Accounts are created by
                administrators and invitations, and we do not knowingly collect
                data from anyone under 18. If you believe a child has an
                account, contact us and we will delete it.
            </p>
        </LegalLayout>
    );
}
