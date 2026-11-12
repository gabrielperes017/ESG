(function () {
    'use strict';
    document.querySelectorAll('input[data-mascara="cep"]').forEach(function (campo) {
        campo.addEventListener('input', function () {
            var numeros = campo.value.replace(/\D/g, '').slice(0, 8);
            campo.value = numeros.length > 5 ? numeros.slice(0, 5) + '-' + numeros.slice(5) : numeros;
        });
    });
})();
