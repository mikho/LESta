import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { setCookieConsent, useCookieConsent } from '@/hooks/use-cookie-consent';
import { cookies } from '@/routes/legal';

export function CookieBanner() {
    const consent = useCookieConsent();

    if (consent !== null) {
        return null;
    }

    return (
        <section
            aria-labelledby="cookie-banner-title"
            className="fixed inset-x-0 bottom-0 z-50 border-t bg-background p-4 shadow-lg"
        >
            <div className="mx-auto flex max-w-4xl flex-col gap-4 sm:flex-row sm:items-center">
                <div className="flex-1 space-y-1 text-sm">
                    <h2 id="cookie-banner-title" className="font-medium">
                        Cookies and local storage
                    </h2>
                    <p className="text-muted-foreground">
                        We always use the cookies the panel needs to work, such
                        as your sign-in session. With your permission we also
                        remember your theme and sidebar choice. Nothing is saved
                        if you decline.{' '}
                        <Link
                            href={cookies()}
                            className="underline underline-offset-4"
                        >
                            Cookie policy
                        </Link>
                    </p>
                </div>
                <div className="flex shrink-0 gap-2">
                    <Button
                        variant="outline"
                        onClick={() => setCookieConsent('denied')}
                    >
                        Decline
                    </Button>
                    <Button onClick={() => setCookieConsent('granted')}>
                        Accept
                    </Button>
                </div>
            </div>
        </section>
    );
}
