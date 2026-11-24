import { FormCheckbox } from '@/components/form-checkbox';
import { Label } from '@/components/ui/label';

/**
 * A mail domain's antivirus or antispam switch. The scanners are optional on a server (ClamAV
 * alone needs about 1 GB of memory), so where the server has none the switch is replaced by a
 * line saying so, and nothing is submitted: the domain keeps its setting for when the scanner is
 * installed.
 */
export function ScannerSwitch({
    name,
    label,
    unavailable,
    available,
    defaultChecked,
}: {
    name: string;
    label: string;
    unavailable: string;
    available: boolean;
    defaultChecked: boolean;
}) {
    if (!available) {
        return <p className="text-sm text-muted-foreground">{unavailable}</p>;
    }

    return (
        <div className="flex items-center space-x-3">
            <FormCheckbox
                id={name}
                name={name}
                defaultChecked={defaultChecked}
            />
            <Label htmlFor={name}>{label}</Label>
        </div>
    );
}
