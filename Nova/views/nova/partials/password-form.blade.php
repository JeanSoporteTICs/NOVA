<form method="POST" action="{{ route('account.password.update') }}">
    @csrf
    <div class="mb-3">
        <label for="current_password" class="form-label">Contraseña actual</label>
        <input id="current_password" name="current_password" type="password" class="form-control" autocomplete="current-password" maxlength="512" required>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Nueva contraseña</label>
        <input id="password" name="password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" aria-describedby="password-help" required>
        <div id="password-help" class="form-text">Usa al menos 8 caracteres y una contraseña distinta de la actual.</div>
    </div>
    <div class="mb-4">
        <label for="password_confirmation" class="form-label">Confirmar nueva contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required>
    </div>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar contraseña</button>
</form>
