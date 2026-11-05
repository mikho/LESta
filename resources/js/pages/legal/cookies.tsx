import { Button } from '@/components/ui/button';
import {
    resetCookieConsent,
    useCookieConsent,
} from '@/hooks/use-cookie-consent';
import LegalLayout from '@/layouts/legal-layout';

export default function Cookies() {
    const consent = useCookieConsent();

    return (
        <LegalLayout title="Cookie policy">
            <p>
                A cookie is a small file stored in your browser. We use these,
                and nothing else:
            </p>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Purpose</th>
                        <th scope="col">Lifetime</th>
                        <th scope="col">Needs consent</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>session cookie</td>
                        <td>Keeps you signed in</td>
                        <td>2 hours</td>
                        <td>No, strictly necessary</td>
                    </tr>
                    <tr>
                        <td>XSRF-TOKEN</td>
                        <td>Protects forms against forged requests</td>
                        <td>Session</td>
                        <td>No, strictly necessary</td>
                    </tr>
                    <tr>
                        <td>cookie_consent</td>
                        <td>Remembers your choice on this page</td>
                        <td>1 year</td>
                        <td>No, strictly necessary</td>
                    </tr>
                    <tr>
                        <td>appearance (also in local storage)</td>
                        <td>Remembers light or dark theme</td>
                        <td>1 year</td>
                        <td>Yes</td>
                    </tr>
                    <tr>
                        <td>sidebar_state</td>
                        <td>Remembers whether the sidebar is open</td>
                        <td>7 days</td>
                        <td>Yes</td>
                    </tr>
                </tbody>
            </table>
            <p>
                If you decline, the two preference items are not saved and any
                already saved are removed. The panel still works, but forgets
                these choices when you leave. We use no analytics or advertising
                cookies.
            </p>
            <p>
                Your choice now:{' '}
                <strong>
                    {consent === 'granted'
                        ? 'accepted'
                        : consent === 'denied'
                          ? 'declined'
                          : 'not made yet'}
                </strong>
            </p>
            <Button variant="outline" onClick={resetCookieConsent}>
                Change cookie settings
            </Button>
        </LegalLayout>
    );
}
