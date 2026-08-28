@props([
    'name',
    'eyebrow',
    'title',
    'badge' => null,
])

<div
    class="servers-modal"
    data-{{ $name }}-modal
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $name }}-modal-title"
>
    <button
        type="button"
        class="servers-modal-backdrop"
        data-{{ $name }}-modal-close
        aria-label="Fechar janela"
    ></button>

    <section class="servers-modal-dialog async-operation-modal">
        <header class="servers-modal-header">
            <div>
                <div class="async-operation-kicker">
                    <p class="eyebrow">{{ $eyebrow }}</p>
                    @if ($badge)
                        <span class="status-badge status-neutral">{{ $badge }}</span>
                    @endif
                </div>
                <h2 id="{{ $name }}-modal-title" data-{{ $name }}-modal-title>{{ $title }}</h2>
            </div>

            <button
                type="button"
                class="users-modal-close"
                data-{{ $name }}-modal-close
                aria-label="Fechar janela"
                title="Fechar"
            >×</button>
        </header>

        {{ $slot }}
    </section>
</div>
