import { Check, Copy } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * A secret the server shows once (a flash for exactly the next page load): the title, what to know
 * about it, and the value in a read-only field with a copy button.
 */
export function OneTimeSecretBanner({
    title,
    secret,
    inputLabel,
    copyLabel,
    children,
}: {
    title: string;
    secret: string;
    inputLabel: string;
    copyLabel: string;
    children: ReactNode;
}) {
    const [copiedText, copy] = useClipboard();
    const IconComponent = copiedText === secret ? Check : Copy;

    return (
        <Alert>
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription className="w-full space-y-3">
                <p>{children}</p>

                <div className="flex w-full items-stretch overflow-hidden rounded-xl border border-border">
                    <input
                        type="text"
                        readOnly
                        value={secret}
                        aria-label={inputLabel}
                        className="h-full w-full bg-background p-3 font-mono text-sm text-foreground outline-none"
                    />
                    <button
                        type="button"
                        onClick={() => copy(secret)}
                        className="border-l border-border px-3 hover:bg-muted"
                        aria-label={copyLabel}
                    >
                        <IconComponent className="w-4" />
                    </button>
                </div>
            </AlertDescription>
        </Alert>
    );
}
