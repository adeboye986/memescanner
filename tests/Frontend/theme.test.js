import assert from 'node:assert/strict';
import test from 'node:test';
import { applyTheme, mountThemeControls, resolveTheme, THEME_STORAGE_KEY } from '../../resources/js/theme.js';
import { navigationMatches } from '../../resources/js/application-shell.js';

class FakeClassList {
    values = new Set();

    toggle(name, force) {
        if (force) {
            this.values.add(name);
        } else {
            this.values.delete(name);
        }
    }

    contains(name) {
        return this.values.has(name);
    }
}

class FakeElement {
    attributes = new Map();

    listeners = new Map();

    addEventListener(name, listener) {
        this.listeners.set(name, listener);
    }

    removeEventListener(name) {
        this.listeners.delete(name);
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    click() {
        this.listeners.get('click')?.();
    }
}

const fakeDocument = () => {
    const toggle = new FakeElement();
    const themeColor = new FakeElement();

    return {
        toggle,
        themeColor,
        documentRef: {
            documentElement: {
                dataset: {},
                classList: new FakeClassList(),
                style: {},
            },
            querySelector: (selector) => selector === '[data-theme-color]' ? themeColor : null,
            querySelectorAll: (selector) => selector === '[data-theme-toggle]' ? [toggle] : [],
        },
    };
};

test('theme resolution honors a valid preference and otherwise follows the operating system', () => {
    assert.equal(resolveTheme({ storedTheme: 'light', prefersDark: true }), 'light');
    assert.equal(resolveTheme({ storedTheme: 'dark', prefersDark: false }), 'dark');
    assert.equal(resolveTheme({ storedTheme: 'invalid', prefersDark: true }), 'dark');
    assert.equal(resolveTheme({ storedTheme: null, prefersDark: false }), 'light');
});

test('applying a theme updates document state, browser color and accessible toggle text', () => {
    const { documentRef, toggle, themeColor } = fakeDocument();

    assert.equal(applyTheme(documentRef, 'dark'), 'dark');
    assert.equal(documentRef.documentElement.dataset.theme, 'dark');
    assert.equal(documentRef.documentElement.classList.contains('dark'), true);
    assert.equal(documentRef.documentElement.style.colorScheme, 'dark');
    assert.equal(themeColor.attributes.get('content'), '#07111d');
    assert.equal(toggle.attributes.get('aria-label'), 'Switch to light theme');
    assert.equal(toggle.attributes.get('aria-pressed'), 'true');
});

test('theme controls use system preference initially and persist an explicit toggle', () => {
    const { documentRef, toggle } = fakeDocument();
    const values = new Map();
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };
    const media = {
        matches: true,
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const controller = mountThemeControls({
        documentRef,
        storage,
        windowRef: {
            localStorage: storage,
            matchMedia: () => media,
        },
    });

    assert.equal(controller.current(), 'dark');
    toggle.click();
    assert.equal(controller.current(), 'light');
    assert.equal(values.get(THEME_STORAGE_KEY), 'light');
    assert.equal(toggle.attributes.get('aria-label'), 'Switch to dark theme');

    controller.destroy();
});

test('theme controls remain usable when browser storage is unavailable', () => {
    const { documentRef, toggle } = fakeDocument();
    const windowRef = {
        matchMedia: () => ({
            matches: false,
            addEventListener: () => {},
            removeEventListener: () => {},
        }),
    };
    Object.defineProperty(windowRef, 'localStorage', {
        get: () => {
            throw new Error('storage blocked');
        },
    });
    const controller = mountThemeControls({ documentRef, windowRef });

    assert.equal(controller.current(), 'light');
    toggle.click();
    assert.equal(controller.current(), 'dark');

    controller.destroy();
});

test('shell navigation search matches destinations case-insensitively', () => {
    assert.equal(navigationMatches('Token Scanner', 'scanner'), true);
    assert.equal(navigationMatches('Opportunities', 'TOKEN'), false);
    assert.equal(navigationMatches('Settings', '  set  '), true);
});
