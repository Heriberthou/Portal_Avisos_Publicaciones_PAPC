/**
 * PAPC | Validación en el navegador de los formularios de acceso (E9).
 *
 * IMPORTANTE: esto es SOLO una ayuda para el usuario. Las mismas reglas se
 * vuelven a comprobar en el servidor (app/nucleo/ValidadorEntrada.php); si
 * este archivo se desactiva o se modifica, PHP sigue rechazando los datos
 * inválidos. Aquí no se revisan permisos ni se transforma la contraseña.
 *
 * Funciones:
 *  - Mensajes por campo (mismos textos que el servidor), con aria-invalid.
 *  - Contador de caracteres de la contraseña (mínimo 15).
 *  - Aviso de coincidencia entre contraseña y confirmación.
 *  - Botón accesible para mostrar u ocultar la contraseña.
 *  - "Procesando..." y bloqueo temporal del botón para evitar envíos dobles.
 *
 * Los errores no aparecen al cargar la página: solo después de que el
 * usuario sale del campo o intenta enviar el formulario.
 *
 * NOM-12/NOM-15: identificadores en camelCase.
 */
(function () {
    'use strict';

    /** Mismo patrón que ValidadorEntrada::PATRON_NOMBRE. */
    var patronNombre = /^[\p{L}\p{M}][\p{L}\p{M} .'\-]*$/u;

    /** Comprobación sintáctica básica de correo (el servidor usa FILTER_VALIDATE_EMAIL). */
    var patronCorreo = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    /**
     * Cuenta caracteres como mb_strlen() de PHP (puntos de código, no bytes).
     * @param {string} texto
     * @returns {number}
     */
    function contarCaracteres(texto) {
        return Array.from(texto).length;
    }

    /**
     * @param {HTMLInputElement} campo
     * @param {string} atributo
     * @param {number} valorPredeterminado
     * @returns {number}
     */
    function leerLimite(campo, atributo, valorPredeterminado) {
        var valor = parseInt(campo.getAttribute(atributo) || '', 10);
        return isNaN(valor) ? valorPredeterminado : valor;
    }

    /**
     * Devuelve el mensaje de error de un campo, o '' si es válido.
     * @param {HTMLElement} campo Input o fieldset con data-validar.
     * @param {HTMLFormElement} formulario
     * @returns {string}
     */
    function obtenerError(campo, formulario) {
        var regla = campo.getAttribute('data-validar');
        var valor = typeof campo.value === 'string' ? campo.value : '';
        var longitud;
        var minimo;
        var maximo;
        var descripcion;

        switch (regla) {
            case 'requerido':
                return valor.trim() === '' ? (campo.getAttribute('data-mensaje-vacio') || 'Este campo es obligatorio.') : '';

            case 'nombre':
                descripcion = campo.getAttribute('data-descripcion') || 'El campo';
                valor = valor.trim().replace(/\s+/g, ' ');
                if (valor === '') {
                    return campo.required ? descripcion + ' es obligatorio.' : '';
                }
                longitud = contarCaracteres(valor);
                minimo = leerLimite(campo, 'minlength', 2);
                maximo = leerLimite(campo, 'maxlength', 60);
                if (longitud < minimo || longitud > maximo) {
                    return descripcion + ' debe tener entre ' + minimo + ' y ' + maximo + ' caracteres.';
                }
                if (!patronNombre.test(valor)) {
                    return descripcion + ' solo puede llevar letras, espacios, puntos, apóstrofos y guiones.';
                }
                return '';

            case 'correo':
                valor = valor.trim();
                maximo = leerLimite(campo, 'maxlength', 150);
                if (valor === '') {
                    return 'Escriba su correo electrónico.';
                }
                if (contarCaracteres(valor) > maximo) {
                    return 'El correo no puede tener más de ' + maximo + ' caracteres.';
                }
                if ((campo.validity && campo.validity.typeMismatch) || !patronCorreo.test(valor)) {
                    return 'Escriba un correo válido, por ejemplo: nombre@ejemplo.com';
                }
                return '';

            case 'contrasena-nueva':
                // Sin recortar: los espacios forman parte de la contraseña.
                minimo = leerLimite(campo, 'minlength', 15);
                maximo = leerLimite(campo, 'maxlength', 128);
                if (valor === '') {
                    return 'Escriba una contraseña.';
                }
                longitud = contarCaracteres(valor);
                if (longitud < minimo) {
                    return 'La contraseña debe tener al menos ' + minimo + ' caracteres.';
                }
                if (longitud > maximo) {
                    return 'La contraseña no puede tener más de ' + maximo + ' caracteres.';
                }
                return '';

            case 'confirmacion':
                if (valor === '') {
                    return 'Escriba de nuevo su contraseña.';
                }
                var original = formulario.querySelector('#' + campo.getAttribute('data-confirma'));
                return original && original.value !== valor ? 'Las contraseñas no coinciden.' : '';

            case 'opcion':
                var nombreGrupo = campo.getAttribute('data-nombre');
                return formulario.querySelector('input[name="' + nombreGrupo + '"]:checked')
                    ? '' : (campo.getAttribute('data-mensaje-vacio') || 'Seleccione una opción.');

            case 'identificador':
                // Mismo criterio que ValidadorEntrada::validarIdentificador().
                valor = valor.replace(/\s+/g, '').toUpperCase();
                if (valor === '') {
                    return 'Escriba su matrícula o identificador institucional.';
                }
                minimo = leerLimite(campo, 'minlength', 4);
                maximo = leerLimite(campo, 'maxlength', 20);
                longitud = contarCaracteres(valor);
                if (longitud < minimo || longitud > maximo || !/^[A-Z0-9][A-Z0-9\-]*$/.test(valor)) {
                    return 'El identificador debe tener entre ' + minimo + ' y ' + maximo + ' letras, números o guiones.';
                }
                return '';

            default:
                return '';
        }
    }

    /**
     * Muestra u oculta el estado de un campo.
     * @param {HTMLElement} campo
     * @param {string} mensajeError
     */
    function pintarEstado(campo, mensajeError) {
        var idsDescripcion = (campo.getAttribute('aria-describedby') || '').split(/\s+/);
        var elementoError = null;
        idsDescripcion.forEach(function (idElemento) {
            if (idElemento.indexOf('error-') === 0) {
                elementoError = document.getElementById(idElemento);
            }
        });

        var esGrupo = campo.tagName === 'FIELDSET';
        if (mensajeError !== '') {
            if (esGrupo) {
                campo.classList.add('grupo-invalido');
            } else {
                campo.setAttribute('aria-invalid', 'true');
            }
        } else if (esGrupo) {
            campo.classList.remove('grupo-invalido');
        } else {
            campo.removeAttribute('aria-invalid');
        }

        if (!elementoError) {
            return;
        }
        elementoError.textContent = mensajeError;
        elementoError.classList.remove('estado-ok');

        // Confirmación correcta: se avisa con texto (no solo con color).
        if (mensajeError === '' && campo.getAttribute('data-validar') === 'confirmacion' && campo.value !== '') {
            elementoError.textContent = 'Las contraseñas coinciden.';
            elementoError.classList.add('estado-ok');
        }
    }

    /**
     * Actualiza el contador "N de 15 caracteres mínimos".
     * @param {HTMLInputElement} campo
     * @param {HTMLElement} contador
     */
    function actualizarContador(campo, contador) {
        var longitud = contarCaracteres(campo.value);
        var minimo = leerLimite(campo, 'minlength', 15);
        var maximo = leerLimite(campo, 'maxlength', 128);

        contador.classList.remove('contador-ok', 'contador-excedido');
        if (longitud > maximo) {
            contador.textContent = longitud + ' de ' + maximo + ' caracteres máximos';
            contador.classList.add('contador-excedido');
        } else if (longitud >= minimo) {
            // No se dice "segura": la longitud sola no lo garantiza.
            contador.textContent = longitud + ' caracteres. Cumple el mínimo.';
            contador.classList.add('contador-ok');
        } else {
            contador.textContent = longitud + ' de ' + minimo + ' caracteres mínimos';
        }
    }

    /**
     * Activa el botón Mostrar/Ocultar. Solo cambia el tipo del input; el
     * valor no se altera.
     * @param {HTMLButtonElement} boton
     */
    function activarBotonMostrar(boton) {
        var campo = document.getElementById(boton.getAttribute('data-mostrar'));
        if (!campo) {
            return;
        }
        boton.hidden = false;
        boton.setAttribute('aria-label', 'Mostrar contraseña');
        boton.addEventListener('click', function () {
            var mostrar = campo.type === 'password';
            campo.type = mostrar ? 'text' : 'password';
            boton.textContent = mostrar ? 'Ocultar' : 'Mostrar';
            boton.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
            boton.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
            campo.focus();
        });
    }

    /**
     * Evita el doble envío mientras el servidor responde: el botón muestra
     * "Procesando..." y se reactiva a los 15 s (por si la red falla) o al
     * volver a la página con el botón Atrás. No bloquea indefinidamente.
     * @param {HTMLFormElement} formulario
     */
    function bloquearEnvioDuplicado(formulario) {
        var boton = formulario.querySelector('button[type="submit"]');
        if (!boton || boton.disabled) {
            return;
        }
        var textoOriginal = boton.textContent;
        // Se desactiva después de que el navegador tome el envío actual.
        window.setTimeout(function () {
            boton.disabled = true;
            boton.setAttribute('aria-busy', 'true');
            boton.textContent = 'Procesando...';
        }, 0);
        var reactivar = function () {
            boton.disabled = false;
            boton.removeAttribute('aria-busy');
            boton.textContent = textoOriginal;
        };
        window.setTimeout(reactivar, 15000);
        window.addEventListener('pageshow', reactivar, { once: true });
    }

    /**
     * @param {HTMLFormElement} formulario
     */
    function prepararFormulario(formulario) {
        // Con JavaScript activo, los mensajes propios sustituyen a los del navegador.
        formulario.noValidate = true;

        var campos = Array.prototype.slice.call(formulario.querySelectorAll('[data-validar]'));
        var tocados = new WeakSet();

        // Los campos que el servidor ya marcó con error se consideran tocados.
        campos.forEach(function (campo) {
            if (campo.getAttribute('aria-invalid') === 'true') {
                tocados.add(campo);
            }
        });

        function validarCampo(campo) {
            var mensajeError = obtenerError(campo, formulario);
            pintarEstado(campo, mensajeError);
            return mensajeError === '';
        }

        function revalidarConfirmaciones(idOriginal) {
            campos.forEach(function (campo) {
                if (campo.getAttribute('data-confirma') === idOriginal && tocados.has(campo)) {
                    validarCampo(campo);
                }
            });
        }

        campos.forEach(function (campo) {
            if (campo.tagName === 'FIELDSET') {
                campo.addEventListener('change', function () {
                    tocados.add(campo);
                    validarCampo(campo);
                });
                return;
            }
            campo.addEventListener('blur', function () {
                tocados.add(campo);
                validarCampo(campo);
            });
            campo.addEventListener('input', function () {
                if (tocados.has(campo)) {
                    validarCampo(campo);
                }
                revalidarConfirmaciones(campo.id);
            });
        });

        Array.prototype.forEach.call(formulario.querySelectorAll('[data-contador]'), function (contador) {
            var campo = document.getElementById(contador.getAttribute('data-contador'));
            if (!campo) {
                return;
            }
            actualizarContador(campo, contador);
            campo.addEventListener('input', function () {
                actualizarContador(campo, contador);
            });
        });

        Array.prototype.forEach.call(formulario.querySelectorAll('[data-mostrar]'), activarBotonMostrar);

        formulario.addEventListener('submit', function (evento) {
            var primerInvalido = null;
            campos.forEach(function (campo) {
                tocados.add(campo);
                if (!validarCampo(campo) && primerInvalido === null) {
                    primerInvalido = campo;
                }
            });
            if (primerInvalido !== null) {
                evento.preventDefault();
                var destino = primerInvalido.tagName === 'FIELDSET'
                    ? primerInvalido.querySelector('input')
                    : primerInvalido;
                destino.focus();
                return;
            }
            bloquearEnvioDuplicado(formulario);
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('form[data-formulario]'), prepararFormulario);
})();
