import type * as React from 'react';

import { Checkbox } from '@/components/ui/checkbox';

/**
 * A checkbox that always submits a value: "1" when checked and "0" when not.
 * A bare Radix checkbox submits "on" when checked, which Laravel's `boolean` rule rejects, and
 * submits nothing when unchecked, so the field could never be turned off. The hidden "0" comes
 * first so the checkbox's own "1" wins when checked.
 */
export function FormCheckbox({
    name,
    ...props
}: React.ComponentProps<typeof Checkbox> & { name: string }) {
    return (
        <>
            <input type="hidden" name={name} value="0" />
            <Checkbox name={name} value="1" {...props} />
        </>
    );
}
