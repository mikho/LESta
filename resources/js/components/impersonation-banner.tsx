import { Form, usePage } from '@inertiajs/react';
import ImpersonationController from '@/actions/App/Http/Controllers/Support/ImpersonationController';
import { Button } from '@/components/ui/button';

/**
 * Shown on every page while App\Actions\Support\StartImpersonation has swapped this session into
 * a hosting customer's own identity -- a real, separately-audited session swap (not the
 * read-only support view), so there must never be a page where an admin could lose track of
 * being in someone else's account. auth.impersonating is set by HandleInertiaRequests only when
 * the session actually carries an active impersonation.
 */
export function ImpersonationBanner() {
    const { auth } = usePage().props;

    if (!auth.impersonating) {
        return null;
    }

    return (
        <div
            className="flex items-center justify-between gap-4 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950"
            data-test="impersonation-banner"
        >
            <span>
                You are viewing as {auth.user.name} ({auth.user.email}), signed
                in as {auth.impersonating.admin_name}.
            </span>

            <Form {...ImpersonationController.destroy.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        disabled={processing}
                        data-test="stop-impersonation-button"
                    >
                        Return to admin
                    </Button>
                )}
            </Form>
        </div>
    );
}
