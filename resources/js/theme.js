export const THEME_STORAGE_KEY = 'meme-scanner-theme';
export const THEMES = Object.freeze(['light', 'dark']);

export const resolveTheme = ({ storedTheme = null, prefersDark = false } = {}) => {
    if (THEMES.includes(storedTheme)) {
        return storedTheme;
    }

    return prefersDark ? 'dark' : 'light';
};

const readStoredTheme = (storage) => {
    try {
        return storage?.getItem(THEME_STORAGE_KEY) ?? null;
    } catch {
        return null;
    }
};

const writeStoredTheme = (storage, theme) => {
    try {
        storage?.setItem(THEME_STORAGE_KEY, theme);
    } catch {
        // The selected theme still applies for this page when storage is unavailable.
    }
};

export const applyTheme = (documentRef, theme) => {
    const resolvedTheme = THEMES.includes(theme) ? theme : 'light';
    const root = documentRef.documentElement;
    root.dataset.theme = resolvedTheme;
    root.classList.toggle('dark', resolvedTheme === 'dark');
    root.style.colorScheme = resolvedTheme;

    documentRef.querySelector('[data-theme-color]')?.setAttribute(
        'content',
        resolvedTheme === 'dark' ? '#07121d' : '#f5f7fa',
    );

    documentRef.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const nextTheme = resolvedTheme === 'dark' ? 'light' : 'dark';
        button.setAttribute('aria-label', 'Switch to '+nextTheme+' theme');
        button.setAttribute('title', 'Switch to '+nextTheme+' theme');
        button.setAttribute('aria-pressed', String(resolvedTheme === 'dark'));
    });

    return resolvedTheme;
};

export const mountThemeControls = ({
    documentRef = document,
    windowRef = window,
    storage = null,
} = {}) => {
    let themeStorage = storage;

    if (themeStorage === null) {
        try {
            themeStorage = windowRef.localStorage;
        } catch {
            themeStorage = null;
        }
    }

    const media = windowRef.matchMedia?.('(prefers-color-scheme: dark)');
    const initialTheme = resolveTheme({
        storedTheme: readStoredTheme(themeStorage) ?? documentRef.documentElement.dataset.theme,
        prefersDark: media?.matches ?? false,
    });
    let currentTheme = applyTheme(documentRef, initialTheme);

    const toggles = Array.from(documentRef.querySelectorAll('[data-theme-toggle]'));
    const toggleTheme = () => {
        currentTheme = applyTheme(documentRef, currentTheme === 'dark' ? 'light' : 'dark');
        writeStoredTheme(themeStorage, currentTheme);
    };
    const followSystemTheme = (event) => {
        if (readStoredTheme(themeStorage) === null) {
            currentTheme = applyTheme(documentRef, event.matches ? 'dark' : 'light');
        }
    };

    toggles.forEach((button) => button.addEventListener('click', toggleTheme));
    media?.addEventListener?.('change', followSystemTheme);

    return {
        current: () => currentTheme,
        destroy: () => {
            toggles.forEach((button) => button.removeEventListener('click', toggleTheme));
            media?.removeEventListener?.('change', followSystemTheme);
        },
    };
};
