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
@elseif ($avatarFigure)
{{-- DNS CENTER CUSTOM AVATARS END --}}
        <span class="avatar-figure-symbol">
            {{ $avatarFigure }}
        </span>
    @else
        {{ $initial }}
    @endif
</span>
