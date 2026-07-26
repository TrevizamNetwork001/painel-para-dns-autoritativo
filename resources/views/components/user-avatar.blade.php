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
        <circle
            cx="32"
            cy="22"
            r="12"
            fill="#f2b27c"
            stroke="#172033"
            stroke-width="3"
        />
        <path
            d="M20 20c1-11 23-14 25 0-7-3-17-3-25 0Z"
            fill="#172033"
        />
        <path
            d="M18 58V47c0-8 6-13 14-13s14 5 14 13v11"
            fill="#1f2937"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M27 35h10l-2 8h-6Z"
            fill="#ffffff"
            stroke="#172033"
            stroke-width="2"
        />
        <path
            d="m32 42-4 16h8Z"
            fill="#ef4444"
            stroke="#172033"
            stroke-width="2"
        />
        <rect
            x="20"
            y="18"
            width="10"
            height="7"
            rx="3"
            fill="#111827"
        />
        <rect
            x="34"
            y="18"
            width="10"
            height="7"
            rx="3"
            fill="#111827"
        />
        <path
            d="M30 21h4"
            stroke="#172033"
            stroke-width="2"
        />
        <path
            d="M28 29c3 2 5 2 8 0"
            fill="none"
            stroke="#172033"
            stroke-width="2"
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
        <path
            d="M16 56V28c0-13 7-22 16-22s16 9 16 22v28Z"
            fill="#111827"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M21 26c4-5 18-5 22 0l-4 18H25Z"
            fill="#1f2937"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linejoin="round"
        />
        <path
            d="M25 28h14"
            stroke="#172033"
            stroke-width="7"
            stroke-linecap="round"
        />
        <circle cx="27" cy="28" r="2" fill="#ffffff" />
        <circle cx="37" cy="28" r="2" fill="#ffffff" />
        <path
            d="M45 41 56 18"
            stroke="#7c4a22"
            stroke-width="4"
            stroke-linecap="round"
        />
        <path
            d="m49 15 10 5-5 10-10-5Z"
            fill="#94a3b8"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linejoin="round"
        />
        <path
            d="M26 38c4 2 8 2 12 0"
            fill="none"
            stroke="#64748b"
            stroke-width="2"
            stroke-linecap="round"
        />
    </svg>
@elseif ($avatarKey === 'engenheiro_obra')
    <svg
        class="avatar-character-svg"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Engenheiro de obra"
    >
        <circle
            cx="32"
            cy="28"
            r="14"
            fill="#b87545"
            stroke="#172033"
            stroke-width="3"
        />
        <path
            d="M15 25c0-12 7-20 17-20s17 8 17 20"
            fill="#facc15"
            stroke="#172033"
            stroke-width="3"
        />
        <path
            d="M11 25h42v7H11Z"
            fill="#facc15"
            stroke="#172033"
            stroke-width="3"
            stroke-linejoin="round"
        />
        <path
            d="M28 6v18M36 6v18"
            stroke="#eab308"
            stroke-width="3"
        />
        <circle cx="26" cy="29" r="2.5" fill="#172033" />
        <circle cx="38" cy="29" r="2.5" fill="#172033" />
        <path
            d="M27 36c3 3 7 3 10 0"
            fill="none"
            stroke="#172033"
            stroke-width="2.5"
            stroke-linecap="round"
        />
        <path
            d="M16 59V50c0-8 7-13 16-13s16 5 16 13v9"
            fill="#f97316"
            stroke="#172033"
            stroke-width="3"
        />
        <path
            d="M26 40 32 48l6-8"
            fill="#ffffff"
            stroke="#172033"
            stroke-width="2"
            stroke-linejoin="round"
        />
        <path
            d="M24 49h16"
            stroke="#facc15"
            stroke-width="3"
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
