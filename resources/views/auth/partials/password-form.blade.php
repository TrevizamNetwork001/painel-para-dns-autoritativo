@if ($errors->any())
    <div class="alert alert-error">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    method="POST"
    action="{{ route('password.change.update') }}"
    class="password-ircenter-form"
>
    @csrf
    @method('PUT')

    <label class="form-field password-current-field">
        <span>
            {{ $isFirstAccess
                ? 'Senha temporária atual'
                : 'Senha atual' }}
        </span>

        <span class="password-field">
            <input
                id="current_password"
                type="password"
                name="current_password"
                autocomplete="current-password"
                required
            >

            <button
                type="button"
                class="password-toggle"
                data-password-toggle
                aria-controls="current_password"
            >
                Mostrar
            </button>
        </span>
    </label>

    <div class="password-new-grid">
        <label class="form-field">
            <span>Nova senha</span>

            <span class="password-field">
                <input
                    id="new_password"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    required
                >

                <button
                    type="button"
                    class="password-toggle"
                    data-password-toggle
                    aria-controls="new_password"
                >
                    Mostrar
                </button>
            </span>
        </label>

        <label class="form-field">
            <span>Confirmar nova senha</span>

            <span class="password-field">
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    required
                >

                <button
                    type="button"
                    class="password-toggle"
                    data-password-toggle
                    aria-controls="password_confirmation"
                >
                    Mostrar
                </button>
            </span>
        </label>
    </div>

    <p class="password-policy-inline">
        Use no mínimo 12 caracteres, incluindo letra maiúscula, letra
        minúscula, número e símbolo.
    </p>

    @if ($isFirstAccess)
        <button type="submit" class="button button-primary">
            Alterar senha e continuar
        </button>
    @else
        <div class="password-form-actions">
            <a
                href="{{ route('profile.edit') }}"
                class="button button-secondary"
            >
                Cancelar
            </a>

            <button type="submit" class="button button-primary">
                Alterar senha
            </button>
        </div>
    @endif
</form>
