{{--
    Light/dark appearance, inline in <head> so the page never flashes in the wrong theme.
    wire:navigate replaces the <html> attributes with those of the new page (which carry no
    "dark"), so resources/js/app.js applies it again on every page swap.
--}}
<script>
    window.pk95ApplyTheme = function () {
        let dark;
        try {
            const stored = localStorage.getItem('color-theme');
            dark = stored === 'dark' || (stored === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
        } catch (e) {
            dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        document.documentElement.classList.toggle('dark', dark);
    };
    window.pk95ApplyTheme();
</script>
