const root = document.documentElement;

function updateThemeIcon() {
    const theme = root.dataset.theme || 'dark';

    document.querySelectorAll('[data-theme-icon]').forEach((icon) => {
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

// DNS CENTER ACCOUNT MENU

document.querySelectorAll('[data-account-menu]').forEach((menu) => {
    const trigger = menu.querySelector('[data-account-menu-trigger]');
    const panel = menu.querySelector('[data-account-menu-panel]');

    if (!trigger || !panel) {
        return;
    }

    const closeMenu = () => {
        menu.classList.remove('is-open');
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    };

    const openMenu = () => {
        document
            .querySelectorAll('[data-account-menu].is-open')
            .forEach((openedMenu) => {
                if (openedMenu === menu) {
                    return;
                }

                openedMenu.classList.remove('is-open');

                const openedPanel = openedMenu.querySelector(
                    '[data-account-menu-panel]'
                );

                const openedTrigger = openedMenu.querySelector(
                    '[data-account-menu-trigger]'
                );

                if (openedPanel) {
                    openedPanel.hidden = true;
                }

                if (openedTrigger) {
                    openedTrigger.setAttribute('aria-expanded', 'false');
                }
            });

        menu.classList.add('is-open');
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
    };

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();

        if (menu.classList.contains('is-open')) {
            closeMenu();
            return;
        }

        openMenu();
    });

    panel.addEventListener('click', (event) => {
        event.stopPropagation();
    });

    document.addEventListener('click', closeMenu);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenu();
            trigger.focus();
        }
    });
});

