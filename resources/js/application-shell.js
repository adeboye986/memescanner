import { mountThemeControls } from './theme.js';

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'summary',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export const navigationMatches = (label, query) => label.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase());

export const mountApplicationShell = ({
    documentRef = document,
    windowRef = window,
} = {}) => {
    const theme = mountThemeControls({ documentRef, windowRef });
    const shell = documentRef.querySelector('[data-application-shell]');

    if (!shell) {
        return { destroy: () => theme.destroy() };
    }

    const sidebar = shell.querySelector('[data-sidebar]');
    const sidebarToggle = shell.querySelector('[data-sidebar-toggle]');
    const sidebarClose = shell.querySelector('[data-sidebar-close]');
    const sidebarBackdrop = shell.querySelector('[data-sidebar-backdrop]');
    const navigationItems = Array.from(shell.querySelectorAll('[data-shell-nav-item]'));
    const navigationSearch = shell.querySelector('[data-navigation-search]');
    const navigationSearchStatus = shell.querySelector('[data-navigation-search-status]');
    const accountMenu = shell.querySelector('[data-account-menu]');
    let focusBeforeSidebar = null;

    const sidebarIsOpen = () => sidebar?.classList.contains('is-open') ?? false;
    const synchronizeSidebarAccessibility = (open = sidebarIsOpen()) => {
        if (!sidebar) {
            return;
        }

        const hiddenOnMobile = windowRef.innerWidth < 1024 && !open;
        sidebar.toggleAttribute('inert', hiddenOnMobile);
        sidebar.setAttribute('aria-hidden', String(hiddenOnMobile));
    };
    const setSidebarOpen = (open, { restoreFocus = true } = {}) => {
        if (!sidebar || !sidebarToggle || !sidebarBackdrop) {
            return;
        }

        if (open) {
            focusBeforeSidebar = documentRef.activeElement;
        }

        sidebar.classList.toggle('is-open', open);
        sidebarBackdrop.hidden = !open;
        sidebarToggle.setAttribute('aria-expanded', String(open));
        documentRef.body.classList.toggle('ms-navigation-open', open);
        synchronizeSidebarAccessibility(open);

        if (open) {
            sidebarClose?.focus();
        } else if (restoreFocus && focusBeforeSidebar instanceof windowRef.HTMLElement) {
            focusBeforeSidebar.focus();
        }
    };

    const filterNavigation = () => {
        const query = navigationSearch?.value ?? '';
        let visibleCount = 0;

        navigationItems.forEach((item) => {
            const visible = navigationMatches(item.textContent ?? '', query);
            item.hidden = !visible;
            visibleCount += visible ? 1 : 0;
        });

        if (navigationSearchStatus) {
            navigationSearchStatus.textContent = query === ''
                ? ''
                : visibleCount+' navigation '+(visibleCount === 1 ? 'result' : 'results');
        }
    };

    const synchronizeActiveNavigation = () => {
        const currentPath = windowRef.location?.pathname;
        const currentHash = windowRef.location?.hash ?? '';
        const exactMatch = navigationItems.find((item) => {
            const url = new URL(item.href, windowRef.location?.href);

            return url.pathname === currentPath && url.hash === currentHash;
        });

        if (!exactMatch) {
            return;
        }

        navigationItems.forEach((item) => {
            const active = item === exactMatch;
            item.classList.toggle('is-active', active);

            if (active) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        });
    };

    const handleDocumentKeydown = (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLocaleLowerCase() === 'k' && navigationSearch) {
            event.preventDefault();
            navigationSearch.focus();

            return;
        }

        if (event.key === 'Escape') {
            if (sidebarIsOpen()) {
                setSidebarOpen(false);
            }

            if (accountMenu?.open) {
                accountMenu.open = false;
                accountMenu.querySelector('summary')?.focus();
            }

            if (documentRef.activeElement === navigationSearch && navigationSearch.value !== '') {
                navigationSearch.value = '';
                filterNavigation();
            }

            return;
        }

        if (event.key !== 'Tab' || !sidebarIsOpen() || !sidebar) {
            return;
        }

        const focusable = Array.from(sidebar.querySelectorAll(focusableSelector)).filter((element) => !element.hidden);
        const first = focusable[0];
        const last = focusable.at(-1);

        if (focusable.length === 0) {
            event.preventDefault();

            return;
        }

        if (event.shiftKey && documentRef.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && documentRef.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const handleDocumentClick = (event) => {
        if (accountMenu?.open && !accountMenu.contains(event.target)) {
            accountMenu.open = false;
        }
    };

    const handleSearchKeydown = (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const firstVisible = navigationItems.find((item) => !item.hidden);

        if (firstVisible) {
            event.preventDefault();
            firstVisible.click();
        }
    };

    const openSidebar = () => setSidebarOpen(true);
    const closeSidebar = () => setSidebarOpen(false);
    const handleResize = () => {
        if (windowRef.innerWidth >= 1024 && sidebarIsOpen()) {
            setSidebarOpen(false, { restoreFocus: false });
        } else {
            synchronizeSidebarAccessibility();
        }
    };
    const closeSidebarAfterNavigation = () => {
        if (windowRef.innerWidth < 1024) {
            setSidebarOpen(false, { restoreFocus: false });
        }
    };

    sidebarToggle?.addEventListener('click', openSidebar);
    sidebarClose?.addEventListener('click', closeSidebar);
    sidebarBackdrop?.addEventListener('click', closeSidebar);
    navigationItems.forEach((item) => item.addEventListener('click', closeSidebarAfterNavigation));
    navigationSearch?.addEventListener('input', filterNavigation);
    navigationSearch?.addEventListener('keydown', handleSearchKeydown);
    documentRef.addEventListener('keydown', handleDocumentKeydown);
    documentRef.addEventListener('click', handleDocumentClick);
    windowRef.addEventListener?.('hashchange', synchronizeActiveNavigation);
    windowRef.addEventListener?.('resize', handleResize);
    synchronizeSidebarAccessibility();
    synchronizeActiveNavigation();

    return {
        destroy: () => {
            theme.destroy();
            sidebarToggle?.removeEventListener('click', openSidebar);
            sidebarClose?.removeEventListener('click', closeSidebar);
            sidebarBackdrop?.removeEventListener('click', closeSidebar);
            navigationItems.forEach((item) => item.removeEventListener('click', closeSidebarAfterNavigation));
            navigationSearch?.removeEventListener('input', filterNavigation);
            navigationSearch?.removeEventListener('keydown', handleSearchKeydown);
            documentRef.removeEventListener('keydown', handleDocumentKeydown);
            documentRef.removeEventListener('click', handleDocumentClick);
            windowRef.removeEventListener?.('hashchange', synchronizeActiveNavigation);
            windowRef.removeEventListener?.('resize', handleResize);
        },
    };
};
