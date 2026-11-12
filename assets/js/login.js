(function () {
    var campo = document.getElementById('senha');
    var botaoOlho = document.querySelector('.mostrar-senha');
    var form = document.getElementById('form-login');

    if (campo && botaoOlho) {
        botaoOlho.addEventListener('click', function () {
            var oculta = campo.type === 'password';
            campo.type = oculta ? 'text' : 'password';
            botaoOlho.textContent = oculta ? 'Ocultar' : 'Mostrar';
            botaoOlho.setAttribute('aria-pressed', oculta ? 'true' : 'false');
        });
    }

    if (form) {
        var enviar = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', function () {
            enviar.disabled = true;
            enviar.textContent = 'Entrando…';
        });
        window.addEventListener('pageshow', function () {
            enviar.disabled = false;
            enviar.textContent = 'Entrar';
        });
    }
})();
