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
    @if ($avatarFigure)
        <span class="avatar-figure-symbol">
            {{ $avatarFigure }}
        </span>
    @else
        {{ $initial }}
    @endif
</span>
