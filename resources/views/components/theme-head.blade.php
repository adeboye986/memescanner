<meta name="theme-color" content="#07111d" data-theme-color>
<script>
    (() => {
        const root = document.documentElement;
        let storedTheme = null;

        try {
            storedTheme = window.localStorage.getItem('meme-scanner-theme');
        } catch {
            storedTheme = null;
        }

        const theme = storedTheme === 'light' || storedTheme === 'dark'
            ? storedTheme
            : (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

        root.dataset.theme = theme;
        root.classList.toggle('dark', theme === 'dark');
        root.style.colorScheme = theme;
        document.querySelector('[data-theme-color]')?.setAttribute(
            'content',
            theme === 'dark' ? '#07111d' : '#f4f7fb',
        );
    })();
</script>
