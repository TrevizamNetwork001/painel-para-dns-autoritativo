const root = document.documentElement;

function updateThemeIcon() {
    const theme = root.dataset.theme || 'dark';

    document.querySelectorAll('[data-theme-icon]').forEach((icon) => {
        icon.textContent = theme === 'dark' ? '☀' : '☾';
    });
}

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const nextTheme =
            root.dataset.theme === 'dark' ? 'light' : 'dark';

        root.dataset.theme = nextTheme;
        localStorage.setItem('dns-center-theme', nextTheme);
        updateThemeIcon();
    });
});

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const inputId = button.getAttribute('aria-controls');
        const input = document.getElementById(inputId);

        if (!input) {
            return;
        }

        const shouldShow = input.type === 'password';

        input.type = shouldShow ? 'text' : 'password';
        button.textContent = shouldShow ? 'Ocultar' : 'Mostrar';
        button.setAttribute(
            'aria-label',
            shouldShow ? 'Ocultar senha' : 'Mostrar senha'
        );
    });
});

updateThemeIcon();

document.querySelectorAll('[data-flash-message]').forEach((message) => {
    window.setTimeout(() => {
        message.classList.add('is-hiding');

        window.setTimeout(() => {
            message.remove();
        }, 350);
    }, 4000);
});
