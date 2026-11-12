(function () {
    'use strict';

    var CHAVE_USUARIO = 'biooleo_usuario';

    function ler(chave) {
        try { return window.localStorage.getItem(chave); } catch (e) { return null; }
    }
    function gravar(chave, valor) {
        try {
            if (valor === null) { window.localStorage.removeItem(chave); }
            else { window.localStorage.setItem(chave, valor); }
        } catch (e) { }
    }

    var form = document.querySelector('form.formulario');
    if (!form) { return; }

    var botao = form.querySelector('button[type="submit"]');
    var rotulo = botao ? botao.querySelector('span') : null;
    var textoOriginal = rotulo ? rotulo.textContent : '';

    var campoUsuario = document.getElementById('email');
    var lembrar = document.getElementById('lembrar');

    if (campoUsuario && lembrar) {
        var salvo = ler(CHAVE_USUARIO);
        if (salvo) {
            if (campoUsuario.value === '') { campoUsuario.value = salvo; }
            lembrar.checked = true;
        }
    }

    var telefone = document.getElementById('telefone');

    function mascaraTelefone(valor) {
        var d = valor.replace(/\D/g, '');
        if (d.length > 11 && d.indexOf('55') === 0) { d = d.slice(2); }
        d = d.slice(0, 11);
        if (d.length > 10) { return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7); }
        if (d.length > 6)  { return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6); }
        if (d.length > 2)  { return '(' + d.slice(0, 2) + ') ' + d.slice(2); }
        if (d.length > 0)  { return '(' + d; }
        return '';
    }

    if (telefone) {
        telefone.addEventListener('input', function () {
            telefone.value = mascaraTelefone(telefone.value);
        });
    }

    var senha = document.getElementById('senha');
    var confirmar = document.getElementById('confirmarSenha');

    function conferirSenhas() {
        confirmar.setCustomValidity(confirmar.value !== senha.value ? 'As senhas não são iguais.' : '');
    }

    if (senha && confirmar) {
        senha.addEventListener('input', conferirSenhas);
        confirmar.addEventListener('input', conferirSenhas);
    }

    form.addEventListener('submit', function () {
        if (campoUsuario && lembrar) {
            gravar(CHAVE_USUARIO, lembrar.checked ? campoUsuario.value.trim() : null);
        }
        if (botao && rotulo) {
            botao.disabled = true;
            rotulo.textContent = botao.getAttribute('data-enviando') || 'Enviando…';
        }
    });

    window.addEventListener('pageshow', function () {
        if (botao && rotulo) {
            botao.disabled = false;
            rotulo.textContent = textoOriginal;
        }
    });
})();
