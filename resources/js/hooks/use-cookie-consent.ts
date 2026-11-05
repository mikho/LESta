import { useSyncExternalStore } from 'react';

export type CookieConsent = 'granted' | 'denied' | null;

const CONSENT_COOKIE = 'cookie_consent';
const PREFERENCE_COOKIES = ['appearance', 'sidebar_state'];
const PREFERENCE_STORAGE_KEYS = ['appearance'];
const ONE_YEAR = 365 * 24 * 60 * 60;

const listeners = new Set<() => void>();

export const readCookieConsent = (): CookieConsent => {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(
        new RegExp(`(?:^|;\\s*)${CONSENT_COOKIE}=([^;]*)`),
    );

    return match?.[1] === 'granted' || match?.[1] === 'denied'
        ? match[1]
        : null;
};

/** Preference cookies and localStorage may only be written after the visitor accepted them. */
export const hasPreferenceConsent = (): boolean =>
    readCookieConsent() === 'granted';

const removePreferences = (): void => {
    for (const name of PREFERENCE_COOKIES) {
        document.cookie = `${name}=;path=/;max-age=0;SameSite=Lax`;
    }

    try {
        for (const key of PREFERENCE_STORAGE_KEYS) {
            localStorage.removeItem(key);
        }
    } catch {
        // Storage can be blocked; nothing is stored then.
    }
};

export const setCookieConsent = (value: 'granted' | 'denied'): void => {
    document.cookie = `${CONSENT_COOKIE}=${value};path=/;max-age=${ONE_YEAR};SameSite=Lax`;

    if (value === 'denied') {
        removePreferences();
    }

    listeners.forEach((listener) => listener());
};

/** Withdraws the stored choice so the banner asks again. */
export const resetCookieConsent = (): void => {
    document.cookie = `${CONSENT_COOKIE}=;path=/;max-age=0;SameSite=Lax`;
    removePreferences();
    listeners.forEach((listener) => listener());
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

export function useCookieConsent(): CookieConsent {
    return useSyncExternalStore(subscribe, readCookieConsent, () => null);
}
