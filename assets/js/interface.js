(function () {
    'use strict';

    document.addEventListener('submit', function (evento) {
        var pergunta = evento.target.getAttribute ? evento.target.getAttribute('data-confirmar') : null;
        if (pergunta && !window.confirm(pergunta)) {
            evento.preventDefault();
        }
    });

    document.addEventListener('click', function (evento) {
        var alvo = evento.target.closest ? evento.target.closest('[data-confirmar], [data-imprimir]') : null;
        if (!alvo) { return; }

        if (alvo.hasAttribute('data-imprimir')) {
            window.print();
            return;
        }

        if (alvo.tagName !== 'FORM' && !window.confirm(alvo.getAttribute('data-confirmar'))) {
            evento.preventDefault();
        }
    });
})();
