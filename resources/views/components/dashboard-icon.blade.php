@props(['name'])

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('server')
        @case('primaries-online')
        @case('secondaries-online')
            <rect x="3.5" y="3.5" width="17" height="7" rx="2"/>
            <rect x="3.5" y="13.5" width="17" height="7" rx="2"/>
            <path d="M6.5 7h.01M6.5 17h.01M12 7h5M12 17h5"/>
            @break
        @case('zone')
            <circle cx="12" cy="12" r="9"/>
            <path d="M3 12h18M12 3c2.5 2.4 3.8 5.4 3.8 9s-1.3 6.6-3.8 9M12 3c-2.5 2.4-3.8 5.4-3.8 9s1.3 6.6 3.8 9"/>
            @break
        @case('zonas-sincronizadas')
            <circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2"/>
            <path d="M12 2v4M12 18v4M2 12h4M18 12h4"/>
            @break
        @case('online')
            <circle cx="12" cy="12" r="2.2"/>
            <path d="M7.9 7.9a5.8 5.8 0 0 0 0 8.2m8.2-8.2a5.8 5.8 0 0 1 0 8.2M5.1 5.1a9.8 9.8 0 0 0 0 13.8m13.8-13.8a9.8 9.8 0 0 1 0 13.8"/>
            @break
        @case('alert')
        @case('zonas-divergentes')
            <path d="M10.2 3.8 2.7 17.2a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.8 3.8a2 2 0 0 0-3.6 0Z"/>
            <path d="M12 9v4.7M12 17.2h.01"/>
            @break
        @case('user')
            <circle cx="12" cy="7.5" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>
            @break
        @case('logs')
            <rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('upload')
            <path d="M12 16V3M7 8l5-5 5 5M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>
            @break
    @endswitch
</svg>
