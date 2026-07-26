@props([
    'user',
    'size' => 'medium',
])

@php
    $avatarKey = $user->avatar_key;
    $initial = str($user->name)->substr(0, 1)->upper();

    $avatarFigure = match ($avatarKey) {
        'astronaut' => '🧑‍🚀',
        'robot' => '🤖',
        'wolf' => '🐺',
        'fox' => '🦊',
        'eagle' => '🦅',
        'owl' => '🦉',
        'lion' => '🦁',
        'tiger' => '🐯',
        'panda' => '🐼',
        'dolphin' => '🐬',
        'rocket' => '🚀',
        'planet' => '🪐',
        'mountain' => '🏔️',
        'cloud' => '☁️',
        'cactus' => '🌵',
        'camera' => '📷',
        'gamepad' => '🎮',
        'ogre' => 'ogre',
        'donkey' => 'donkey',
        'ceo' => 'ceo',
        'anta' => 'anta',
        'peixe' => 'peixe',
        'carrasco' => 'carrasco',
        'engenheiro_obra' => 'engenheiro_obra',

        default => null,
    };
@endphp

<span
    {{ $attributes->class([
        'user-avatar-component',
        'user-avatar-'.$size,
        $avatarFigure ? 'avatar-figure' : 'avatar-initial',
    ]) }}
    aria-hidden="true"
>
    {{-- DNS CENTER CUSTOM AVATARS START --}}
@if ($avatarKey === 'ogre')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Ogro verde"
    >
        <path
            d="M14 23 5 15l4 14M50 23l9-8-4 14"
            fill="#84cc16"
            stroke="#172033"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <rect
            x="12"
            y="12"
            width="40"
            height="44"
            rx="19"
            fill="#84cc16"
            stroke="#172033"
            stroke-width="3"
        />
        <circle cx="23" cy="28" r="3" fill="#172033" />
        <circle cx="41" cy="28" r="3" fill="#172033" />
        <path
            d="M28 35h8"
            stroke="#4d7c0f"
            stroke-width="2.5"
            stroke-linecap="round"
        />
        <path
            d="M21 43c5 5 17 5 22 0"
            fill="#ffffff"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
@elseif ($avatarKey === 'donkey')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Jegue"
    >
        <path
            d="M19 22 11 4c9 2 13 8 15 18M45 22l8-18c-9 2-13 8-15 18"
            fill="#94a3b8"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M14 32c0-14 8-22 18-22s18 8 18 22v10c0 11-8 18-18 18s-18-7-18-18Z"
            fill="#94a3b8"
            stroke="#172033"
            stroke-width="3"
        />
        <path
            d="M20 39c0-7 5-11 12-11s12 4 12 11v8c0 7-5 11-12 11s-12-4-12-11Z"
            fill="#dbe3ec"
            stroke="#172033"
            stroke-width="2.5"
        />
        <circle cx="23" cy="29" r="3" fill="#172033" />
        <circle cx="41" cy="29" r="3" fill="#172033" />
        <ellipse cx="27" cy="44" rx="2" ry="2.5" fill="#172033" />
        <ellipse cx="37" cy="44" rx="2" ry="2.5" fill="#172033" />
        <path
            d="M26 51c4 3 8 3 12 0"
            fill="none"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linecap="round"
        />
    </svg>

@elseif ($avatarKey === 'ceo')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="CEO"
    >
        {{-- Corpo e terno --}}
        <path
            d="M14 61V49c0-10 7-16 18-16s18 6 18 16v12Z"
            fill="#26364d"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        <path
            d="m21 39 11 10 11-10"
            fill="#ffffff"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linejoin="round"
        />

        <path
            d="m32 46-5 15h10Z"
            fill="#f97316"
            stroke="#172033"
            stroke-width="2"
            stroke-linejoin="round"
        />

        {{-- Cabeça maior --}}
        <circle
            cx="32"
            cy="23"
            r="15"
            fill="#d9955e"
            stroke="#172033"
            stroke-width="3"
        />

        {{-- Cabelo --}}
        <path
            d="M17 23C17 11 24 5 33 5c10 0 16 7 15 18
               -4-6-10-9-16-9-6 0-11 3-15 9Z"
            fill="#111827"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linejoin="round"
        />

        {{-- Óculos escuros maiores --}}
        <rect
            x="18"
            y="20"
            width="12"
            height="8"
            rx="3"
            fill="#111827"
            stroke="#020617"
            stroke-width="2"
        />

        <rect
            x="34"
            y="20"
            width="12"
            height="8"
            rx="3"
            fill="#111827"
            stroke="#020617"
            stroke-width="2"
        />

        <path
            d="M30 23h4"
            stroke="#020617"
            stroke-width="2.5"
            stroke-linecap="round"
        />

        {{-- Reflexo dos óculos --}}
        <path
            d="m21 22 3 3M37 22l3 3"
            stroke="#64748b"
            stroke-width="1.5"
            stroke-linecap="round"
        />

        {{-- Sorriso --}}
        <path
            d="M26 31c4 4 8 4 12 0"
            fill="none"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linecap="round"
        />
    </svg>
@elseif ($avatarKey === 'anta')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Anta"
    >
        <path
            d="M17 20 12 9c7 0 11 4 13 11M47 20l5-11c-7 0-11 4-13 11"
            fill="#6b4f3b"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M13 31c0-13 8-21 19-21s19 8 19 21v10c0 12-8 19-19 19s-19-7-19-19Z"
            fill="#6b4f3b"
            stroke="#172033"
            stroke-width="3"
        />
        <path
            d="M23 33c0-7 4-11 9-11s9 4 9 11v14c0 7-4 11-9 11s-9-4-9-11Z"
            fill="#9a765b"
            stroke="#172033"
            stroke-width="2.5"
        />
        <circle cx="22" cy="29" r="2.5" fill="#172033" />
        <circle cx="42" cy="29" r="2.5" fill="#172033" />
        <ellipse cx="28" cy="46" rx="2" ry="2.5" fill="#172033" />
        <ellipse cx="36" cy="46" rx="2" ry="2.5" fill="#172033" />
        <path
            d="M28 53c3 2 5 2 8 0"
            fill="none"
            stroke="#172033"
            stroke-width="2"
            stroke-linecap="round"
        />
    </svg>
@elseif ($avatarKey === 'peixe')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Peixe"
    >
        <path
            d="M47 22 59 13v38L47 42"
            fill="#38bdf8"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M8 32c7-13 17-18 29-15 8 2 13 8 13 15s-5 13-13 15C25 50 15 45 8 32Z"
            fill="#38bdf8"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M28 18 36 8l7 12M28 46l8 10 7-12"
            fill="#0ea5e9"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linejoin="round"
        />
        <circle cx="24" cy="28" r="3" fill="#ffffff" />
        <circle cx="24" cy="28" r="1.5" fill="#172033" />
        <path
            d="M14 36c4 2 7 2 10 0"
            fill="none"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linecap="round"
        />
        <path
            d="M34 22c4 6 4 14 0 20"
            fill="none"
            stroke="#0284c7"
            stroke-width="2"
            stroke-linecap="round"
        />
    </svg>

@elseif ($avatarKey === 'carrasco')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Carrasco"
    >
        {{-- Machado atrás do personagem --}}
        <path
            d="M44 44 55 17"
            stroke="#9a5b2f"
            stroke-width="5"
            stroke-linecap="round"
        />

        <path
            d="m48 12 12 5-5 13-12-5Z"
            fill="#cbd5e1"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        <path
            d="m52 15 5 2"
            stroke="#ffffff"
            stroke-width="2"
            stroke-linecap="round"
            opacity=".8"
        />

        {{-- Corpo --}}
        <path
            d="M13 61V48c0-10 8-16 19-16s19 6 19 16v13Z"
            fill="#334155"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        {{-- Capuz externo, mais claro para funcionar no tema escuro --}}
        <path
            d="M14 31C14 14 21 5 32 5s18 9 18 26
               L45 48H19Z"
            fill="#475569"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        {{-- Abertura visível do rosto --}}
        <path
            d="M21 27c0-9 4-15 11-15s11 6 11 15v7
               c0 8-4 13-11 13s-11-5-11-13Z"
            fill="#b9784f"
            stroke="#172033"
            stroke-width="2.5"
        />

        {{-- Máscara preta ao redor dos olhos --}}
        <path
            d="M22 25c5-5 15-5 20 0l-3 8H25Z"
            fill="#111827"
            stroke="#172033"
            stroke-width="2"
            stroke-linejoin="round"
        />

        <circle cx="27" cy="28" r="2.3" fill="#f8fafc" />
        <circle cx="37" cy="28" r="2.3" fill="#f8fafc" />

        <circle cx="27" cy="28" r="1" fill="#172033" />
        <circle cx="37" cy="28" r="1" fill="#172033" />

        {{-- Nariz e expressão --}}
        <path
            d="M32 31v4"
            stroke="#7c4a32"
            stroke-width="2"
            stroke-linecap="round"
        />

        <path
            d="M27 39c3-2 7-2 10 0"
            fill="none"
            stroke="#172033"
            stroke-width="2.3"
            stroke-linecap="round"
        />

        {{-- Cordão do capuz --}}
        <path
            d="M23 47 19 57M41 47l4 10"
            stroke="#94a3b8"
            stroke-width="2.5"
            stroke-linecap="round"
        />
    </svg>

@elseif ($avatarKey === 'engenheiro_obra')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Engenheiro"
    >
        {{-- Corpo --}}
        <path
            d="M13 61V49c0-10 8-16 19-16s19 6 19 16v12Z"
            fill="#f97316"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        {{-- Faixas refletivas --}}
        <path
            d="M18 51h28M25 37l7 10 7-10"
            fill="none"
            stroke="#facc15"
            stroke-width="3"
            stroke-linejoin="round"
        />

        {{-- Cabeça claramente visível --}}
        <circle
            cx="32"
            cy="28"
            r="14"
            fill="#b9784f"
            stroke="#172033"
            stroke-width="3"
        />

        {{-- Orelhas --}}
        <circle
            cx="17.5"
            cy="29"
            r="4"
            fill="#b9784f"
            stroke="#172033"
            stroke-width="2"
        />

        <circle
            cx="46.5"
            cy="29"
            r="4"
            fill="#b9784f"
            stroke="#172033"
            stroke-width="2"
        />

        {{-- Capacete mais alto, sem cobrir o rosto --}}
        <path
            d="M17 22C17 11 23 5 32 5s15 6 15 17Z"
            fill="#facc15"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        <path
            d="M12 21h40v7H12Z"
            fill="#facc15"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />

        <path
            d="M27 6v15M37 6v15"
            stroke="#eab308"
            stroke-width="3"
        />

        {{-- Rosto --}}
        <circle cx="26" cy="30" r="2.4" fill="#172033" />
        <circle cx="38" cy="30" r="2.4" fill="#172033" />

        <path
            d="M32 31v4"
            stroke="#7c4a32"
            stroke-width="2"
            stroke-linecap="round"
        />

        <path
            d="M26 38c4 4 8 4 12 0"
            fill="none"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linecap="round"
        />

        {{-- Gola da camisa --}}
        <path
            d="m25 40 7 8 7-8"
            fill="#f8fafc"
            stroke="#172033"
            stroke-width="2"
            stroke-linejoin="round"
        />
    </svg>
@elseif ($avatarFigure)
{{-- DNS CENTER CUSTOM AVATARS END --}}
        <span class="avatar-figure-symbol">
            {{ $avatarFigure }}
        </span>
    @else
        {{ $initial }}
    @endif
</span>
