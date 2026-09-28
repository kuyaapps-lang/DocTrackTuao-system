<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (() => {
            const key = 'doctrack_theme';
            let saved = null;
            try {
                saved = localStorage.getItem(key);
            } catch {
                // A blocked storage API should not prevent the application from loading.
            }
            const theme = saved === 'light' || saved === 'dark'
                ? saved
                : window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', theme === 'dark');
            document.documentElement.dataset.theme = theme;
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    @vite('resources/js/app.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>
