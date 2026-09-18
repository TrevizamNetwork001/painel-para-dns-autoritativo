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

document.querySelectorAll('[data-flash-toast]').forEach((toast) => {
    const closeButton = toast.querySelector('[data-flash-toast-close]');
    const configuredTimeout = Number(toast.dataset.flashTimeout);
    const timeout = Number.isFinite(configuredTimeout)
        && configuredTimeout > 0
        ? configuredTimeout
        : 6000;

    let timer;
    let startedAt;
    let remaining = timeout;

    const dismiss = () => {
        window.clearTimeout(timer);

        if (toast.classList.contains('is-leaving')) {
            return;
        }

        toast.classList.add('is-leaving');

        window.setTimeout(() => {
            toast.remove();
        }, 260);
    };

    const startTimer = () => {
        window.clearTimeout(timer);
        startedAt = Date.now();
        timer = window.setTimeout(dismiss, remaining);
    };

    const pauseTimer = () => {
        window.clearTimeout(timer);

        if (startedAt !== undefined) {
            remaining = Math.max(
                remaining - (Date.now() - startedAt),
                500
            );
        }
    };

    closeButton?.addEventListener('click', dismiss);
    toast.addEventListener('mouseenter', pauseTimer);
    toast.addEventListener('mouseleave', startTimer);

    startTimer();
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

document
    .querySelectorAll('[data-platform-organization-context]')
    .forEach((select) => {
        select.addEventListener('change', () => {
            select.closest('[data-platform-organization-context-form]')
                ?.requestSubmit();
        });
    });


// DNS CENTER USERS UI 2.0

const userModal = document.querySelector('[data-user-modal]');

if (userModal) {
    const openButtons = document.querySelectorAll(
        '[data-user-modal-open]'
    );

    const closeButtons = userModal.querySelectorAll(
        '[data-user-modal-close]'
    );

    const firstInput = userModal.querySelector('input[name="name"]');

    const openUserModal = () => {
        userModal.classList.add('is-open');
        userModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('users-modal-open');

        window.setTimeout(() => {
            firstInput?.focus();
        }, 50);
    };

    const closeUserModal = () => {
        userModal.classList.remove('is-open');
        userModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('users-modal-open');
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', openUserModal);
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', closeUserModal);
    });

    document.addEventListener('keydown', (event) => {
        if (
            event.key === 'Escape'
            && userModal.classList.contains('is-open')
        ) {
            closeUserModal();
        }
    });

    if (document.querySelector('[data-open-user-modal-on-error]')) {
        openUserModal();
    }
}

const usersSearch = document.querySelector('[data-users-search]');
const usersRoleFilter = document.querySelector(
    '[data-users-role-filter]'
);
const usersStatusFilter = document.querySelector(
    '[data-users-status-filter]'
);
const usersClearFilters = document.querySelector(
    '[data-users-clear-filters]'
);
const userRows = Array.from(
    document.querySelectorAll('[data-user-row]')
);
const usersFilterEmpty = document.querySelector(
    '[data-users-filter-empty]'
);

const normalizeUserFilterText = (value) => {
    return value
        .toLocaleLowerCase('pt-BR')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
};

const applyUserFilters = () => {
    const search = normalizeUserFilterText(
        usersSearch?.value || ''
    );

    const role = usersRoleFilter?.value || '';
    const status = usersStatusFilter?.value || '';

    let visibleCount = 0;

    userRows.forEach((row) => {
        const rowSearch = normalizeUserFilterText(
            row.dataset.userSearch || ''
        );

        const matchesSearch =
            !search || rowSearch.includes(search);

        const matchesRole =
            !role || row.dataset.userRole === role;

        const matchesStatus =
            !status || row.dataset.userStatus === status;

        const visible =
            matchesSearch && matchesRole && matchesStatus;

        row.hidden = !visible;

        if (visible) {
            visibleCount += 1;
        }
    });

    if (usersFilterEmpty) {
        usersFilterEmpty.hidden =
            visibleCount > 0 || userRows.length === 0;
    }
};

usersSearch?.addEventListener('input', applyUserFilters);
usersRoleFilter?.addEventListener('change', applyUserFilters);
usersStatusFilter?.addEventListener('change', applyUserFilters);

usersClearFilters?.addEventListener('click', () => {
    if (usersSearch) {
        usersSearch.value = '';
    }

    if (usersRoleFilter) {
        usersRoleFilter.value = '';
    }

    if (usersStatusFilter) {
        usersStatusFilter.value = '';
    }

    applyUserFilters();
});

// DNS SERVERS V1

const serverModal = document.querySelector('[data-server-modal]');

document.querySelectorAll('[data-zone-server-pair]').forEach((pair) => {
    const primary = pair.querySelector('[name="primary_server_id"]');
    const secondary = pair.querySelector('[name="secondary_server_id"]');
    if (!primary || !secondary) return;

    const updateOptions = () => {
        primary.querySelectorAll('option[value]').forEach((option) => {
            option.disabled = Boolean(secondary.value) && option.value === secondary.value;
        });
        secondary.querySelectorAll('option[value]').forEach((option) => {
            option.disabled = Boolean(primary.value) && option.value === primary.value;
        });
        secondary.setCustomValidity(
            primary.value && primary.value === secondary.value
                ? 'Escolha um servidor diferente do principal.'
                : ''
        );
    };

    primary.addEventListener('change', updateOptions);
    secondary.addEventListener('change', updateOptions);
    updateOptions();
});

if (serverModal) {
    const serverForm = serverModal.querySelector('[data-server-form]');
    const serverMethod = serverModal.querySelector('[data-server-method]');
    const serverTitle = serverModal.querySelector(
        '[data-server-modal-title]'
    );
    const serverSubmit = serverModal.querySelector(
        '[data-server-submit-label]'
    );

    const openServerModal = () => {
        serverModal.classList.add('is-open');
        serverModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    const closeServerModal = () => {
        serverModal.classList.remove('is-open');
        serverModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    const prepareCreateServer = () => {
        serverForm.reset();
        serverForm.action = serverForm.dataset.storeAction;
        serverMethod.value = 'POST';
        serverTitle.textContent = 'Novo servidor';
        serverSubmit.textContent = 'Criar servidor';
        openServerModal();
    };

    document
        .querySelectorAll('[data-server-modal-open]')
        .forEach((button) => {
            button.addEventListener('click', prepareCreateServer);
        });

    document
        .querySelectorAll('[data-server-modal-close]')
        .forEach((button) => {
            button.addEventListener('click', closeServerModal);
        });

    document
        .querySelectorAll('[data-server-edit]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                serverForm.action = serverForm.dataset.updateTemplate
                    .replace('__ID__', button.dataset.serverId);

                serverMethod.value = 'PUT';
                serverTitle.textContent = 'Editar servidor';
                serverSubmit.textContent = 'Salvar alterações';

                serverForm.elements.name.value =
                    button.dataset.serverName || '';

                serverForm.elements.hostname.value =
                    button.dataset.serverHostname || '';

                serverForm.elements.ipv4_address.value =
                    button.dataset.serverIpv4 || '';

                serverForm.elements.ipv6_address.value =
                    button.dataset.serverIpv6 || '';

                serverForm.elements.role.value =
                    button.dataset.serverRole || 'primary';

                serverForm.elements.environment.value =
                    button.dataset.serverEnvironment || 'production';

                serverForm.elements.notes.value =
                    button.dataset.serverNotes || '';

                openServerModal();
            });
        });

    if (document.querySelector('[data-server-open-on-error]')) {
        openServerModal();
    }
}
