(function () {
    const appearance = document.documentElement.getAttribute('data-appearance') || 'system';

    if (appearance !== 'system') {
        return;
    }

    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark');
    }
})();
