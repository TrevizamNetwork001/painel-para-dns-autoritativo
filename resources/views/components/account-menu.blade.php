@php
    $currentUser = auth()->user();

    $currentRole = $currentUser->roleForOrganization(
        $currentUser->current_organization_id
    );

    $roleLabel = match ($currentRole) {
        'organization_admin' => 'Administrador',
        'operator' => 'Operador',
        'viewer' => 'Visualizador',
        default => $currentUser->is_platform_admin
            ? 'Administrador da plataforma'
            : 'Usuário',
    };
@endphp

<div class="header-account">
    <div class="organization-context">
        <span>Empresa atual</span>

        <strong>
            {{ $currentUser->currentOrganization?->name
                ?? 'Administração da plataforma' }}
        </strong>
    </div>

    <button
        type="button"
        class="theme-toggle"
        data-theme-toggle
        aria-label="Alternar tema"
        title="Alternar tema"
    >
        <span data-theme-icon>◐</span>
    </button>

    <div class="account-menu" data-account-menu>
        <button
            type="button"
            class="account-menu-trigger"
            data-account-menu-trigger
            aria-haspopup="true"
            aria-expanded="false"
        >
            <x-user-avatar
                :user="$currentUser"
                size="small"
            />

            <span class="account-trigger-info">
                <strong>{{ $currentUser->name }}</strong>
                <small>{{ $roleLabel }}</small>
            </span>

            <svg
                class="account-chevron"
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
            >
                <path
                    d="m7 10 5 5 5-5"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        </button>

        <div
            class="account-menu-panel"
            data-account-menu-panel
            role="menu"
            hidden
        >
            <div class="account-menu-header">
                <x-user-avatar
                    :user="$currentUser"
                    size="large"
                />

                <div>
                    <strong>{{ $currentUser->name }}</strong>
                    <span>{{ $currentUser->email }}</span>
                    <small>{{ $roleLabel }}</small>
                </div>
            </div>

            <div class="account-menu-divider"></div>

            <a
                href="{{ route('profile.edit') }}"
                class="account-menu-item"
                role="menuitem"
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                >
                    <path
                        d="M20 21a8 8 0 0 0-16 0m8-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

                Meu perfil
            </a>

            <a
                href="{{ route('password.change') }}"
                class="account-menu-item"
                role="menuitem"
            >
                <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                >
                    <path
                        d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 0 0-8 0v4h8Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

                Alterar senha
            </a>

            <div class="account-menu-divider"></div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button
                    type="submit"
                    class="account-menu-item account-menu-logout"
                    role="menuitem"
                >
                    <svg
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M10 17l5-5-5-5m5 5H3m10-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                    Sair
                </button>
            </form>
        </div>
    </div>
</div>
