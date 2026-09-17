@props(['active' => null])

@php
    $user = auth()->user();
    $organizationId = $user?->current_organization_id;
    $canManageUsers = $user && (
        $user->is_platform_admin
        || $user->roleForOrganization($organizationId) === 'organization_admin'
    );
    $canManageOrganizations = $user && $user->is_platform_admin;
@endphp

<aside class="sidebar app-sidebar">
    <div class="sidebar-brand">
        <div class="brand-mark brand-mark-small">
            <span>DNS</span>
        </div>

        <div>
            <strong>DNS Center</strong>
            <span>Operations Platform</span>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="Navegação principal">
        <a href="{{ route('dashboard') }}" @class(['nav-item', 'is-active' => $active === 'dashboard'])>
            <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="4" y="4" width="6" height="6" rx="1" />
                    <rect x="14" y="4" width="6" height="6" rx="1" />
                    <rect x="4" y="14" width="6" height="6" rx="1" />
                    <rect x="14" y="14" width="6" height="6" rx="1" />
                </svg>
            </span>
            Dashboard
        </a>

        <span class="nav-section">DNS autoritativo</span>

        <a href="{{ route('servers.index') }}" @class(['nav-item', 'is-active' => $active === 'servers'])>
            <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="4" y="3" width="16" height="7" rx="2" />
                    <rect x="4" y="14" width="16" height="7" rx="2" />
                    <path d="M8 6.5h.01M8 17.5h.01M12 6.5h5M12 17.5h5" />
                </svg>
            </span>
            Servidores
        </a>

        <a href="{{ route('nameservers.index') }}" @class(['nav-item', 'is-active' => $active === 'nameservers'])>
            <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M7 7h12M15 3l4 4-4 4M17 17H5M9 13l-4 4 4 4" />
                </svg>
            </span>
            Nameservers
        </a>

        <a href="{{ route('zones.index') }}" @class(['nav-item', 'is-active' => $active === 'zones'])>
            <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21C9.6 18.5 8.4 15.5 8.4 12S9.6 5.5 12 3Z" />
                </svg>
            </span>
            Domínios
        </a>

        <a href="{{ route('zones.reverse') }}" @class(['nav-item', 'is-active' => $active === 'zones-reverse'])>
            <span class="nav-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M4 7h11M8 3l-4 4 4 4" />
                    <path d="M20 17H9M16 13l4 4-4 4" />
                </svg>
            </span>
            Reversos
        </a>

        <span class="nav-section">Operações</span>

        @if ($canManageUsers && Route::has('users.index'))
            <a href="{{ route('users.index') }}" @class(['nav-item', 'is-active' => $active === 'users'])>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="9" cy="8" r="3" />
                        <path d="M3.5 20a5.5 5.5 0 0 1 11 0M16 5.5a3 3 0 0 1 0 5.5M17 14a5 5 0 0 1 3.5 4.8" />
                    </svg>
                </span>
                Usuários
            </a>
        @endif

        @if ($canManageUsers && Route::has('audit.index'))
            <a href="{{ route('audit.index') }}" @class(['nav-item', 'is-active' => $active === 'audit'])>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M9 3h6l3 3v15H6V3z" />
                        <path d="M9 9h6M9 13h6M9 17h4" />
                    </svg>
                </span>
                Auditoria
            </a>
        @endif

        @if ($canManageOrganizations && Route::has('organizations.index'))
            <span class="nav-section">Plataforma</span>

            <a href="{{ route('organizations.index') }}" @class(['nav-item', 'is-active' => $active === 'organizations'])>
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6" />
                    </svg>
                </span>
                Empresas
            </a>
        @endif
    </nav>
</aside>
