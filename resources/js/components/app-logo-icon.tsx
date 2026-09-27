import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <rect x="8" y="6" width="10" height="28" rx="3" />
            <rect x="8" y="26" width="24" height="8" rx="3" />
            <circle cx="32" cy="10" r="5" />
        </svg>
    );
}
