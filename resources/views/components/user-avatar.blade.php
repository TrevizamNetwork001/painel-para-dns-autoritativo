@props([
    'user',
    'size' => 'medium',
])

@php
    $avatarKey = $user->avatar_key;
    $initial = str($user->name)->substr(0, 1)->upper();
@endphp

<span
    {{ $attributes->class([
        'user-avatar-component',
        'user-avatar-'.$size,
        $avatarKey ? 'avatar-'.$avatarKey : 'avatar-initial',
    ]) }}
    aria-hidden="true"
>
    @if ($avatarKey)
        <span class="avatar-face">
            @switch($avatarKey)
                @case('amber') ◆ @break
                @case('blue') ◉ @break
                @case('cyan') ◈ @break
                @case('emerald') ▲ @break
                @case('grape') ✦ @break
                @case('indigo') ✹ @break
                @case('lime') ● @break
                @case('orange') ◇ @break
                @case('pink') ✿ @break
                @case('red') ◆ @break
                @case('slate') ■ @break
                @case('violet') ✧ @break
            @endswitch
        </span>
    @else
        {{ $initial }}
    @endif
</span>
