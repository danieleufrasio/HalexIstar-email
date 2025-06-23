let enviando = window.rotinaEnviando;
let intervalo = 0;
let unidade = 'segundos';

function renderHorarios(dates, containerId, horarios, prefix, disabled) {
    let html = '';
    dates.forEach(date => {
        let value = horarios[date] || '09:00';
        html += `<div style="margin-bottom:6px;">
            <span>${date}</span>
            <input type="time" name="${prefix}[${date}]" value="${value}" class="hora-data" ${disabled ? 'disabled' : ''} style="margin-left:10px; border-radius:8px; border:1px solid #ccc; padding:2px 8px;">
        </div>`;
    });
    document.getElementById(containerId).innerHTML = html;
}

$(function() {
    // Dias úteis
    let selectedDates = window.rotinaDatasEnvio;
    let horariosDatasEnvio = window.rotinaHorariosDatasEnvio;
    let disabledInputs = enviando ? true : false;

    $("#datas_envio").multiDatesPicker({
        dateFormat: 'yy-mm-dd',
        beforeShowDay: function(date) {
            return [date.getDay() != 0 && date.getDay() != 6, ""];
        },
        onSelect: function(dateText, inst) {
            selectedDates = $("#datas_envio").multiDatesPicker('getDates');
            $("#datas_selecionadas").val(selectedDates.join(','));
            let label = selectedDates.length ? selectedDates.join(', ') : 'Nenhuma data selecionada';
            $("#datas_escolhidas_label").text(label);
            renderHorarios(selectedDates, 'horarios_datas_envio', horariosDatasEnvio, 'horarios_datas_envio', disabledInputs);
        }
    });
    $("#datas_envio").multiDatesPicker('addDates', selectedDates);
    let label = selectedDates.length ? selectedDates.join(', ') : 'Nenhuma data selecionada';
    $("#datas_escolhidas_label").text(label);
    $("#datas_selecionadas").val(selectedDates.join(','));
    renderHorarios(selectedDates, 'horarios_datas_envio', horariosDatasEnvio, 'horarios_datas_envio', disabledInputs);

    // Dias avulsos
    let avulsas = window.rotinaDatasAvulsas;
    let horariosDatasAvulsas = window.rotinaHorariosDatasAvulsas;
    $("#datas_avulsas").multiDatesPicker({
        dateFormat: 'yy-mm-dd',
        onSelect: function(dateText, inst) {
            avulsas = $("#datas_avulsas").multiDatesPicker('getDates');
            $("#datas_avulsas_selecionadas").val(avulsas.join(','));
            let label = avulsas.length ? avulsas.join(', ') : 'Nenhuma data selecionada';
            $("#datas_avulsas_label").text(label);
            renderHorarios(avulsas, 'horarios_datas_avulsas', horariosDatasAvulsas, 'horarios_datas_avulsas', disabledInputs);
        }
    });
    $("#datas_avulsas").multiDatesPicker('addDates', avulsas);
    let labelAvulsa = avulsas.length ? avulsas.join(', ') : 'Nenhuma data selecionada';
    $("#datas_avulsas_label").text(labelAvulsa);
    $("#datas_avulsas_selecionadas").val(avulsas.join(','));
    renderHorarios(avulsas, 'horarios_datas_avulsas', horariosDatasAvulsas, 'horarios_datas_avulsas', disabledInputs);

    // Tagify para destinatários estilo chips
    let destinatariosInput = document.querySelector('input[name=destinatarios]');
    let tagify = new Tagify(destinatariosInput, {
        delimiters: ",; ",
        pattern: /^[\w\.-]+@[\w\.-]+\.\w{2,}$/,
        whitelist: [],
        dropdown: { enabled: 0 },
        editTags: false,
        duplicates: false
    });
    if (enviando && window.rotinaDados['destinatarios']) {
        tagify.addTags(JSON.parse(window.rotinaDados['destinatarios']));
    }
});

// CKEditor 5
let editorInstance;
window.addEventListener('DOMContentLoaded', function() {
    ClassicEditor.create(document.querySelector('#editor'), {
        toolbar: [
            'heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', 'imageUpload', 'undo', 'redo'
        ],
        image: {
            toolbar: [ 'imageTextAlternative', 'imageStyle:full', 'imageStyle:side' ]
        }
    }).then(editor => {
        editorInstance = editor;
        if (enviando && window.rotinaDados['mensagem']) {
            editor.setData(window.rotinaDados['mensagem']);
        }
    }).catch(error => { console.error(error); });
});

window.iniciarLoop = function() {
    if (editorInstance) {
        document.getElementById('editor').value = editorInstance.getData();
    }
    // Tagify: salva como JSON
    let destinatarios = document.querySelector('input[name=destinatarios]').value;
    document.getElementById('destinatarios_json').value = destinatarios;
    intervalo = parseInt(document.getElementById('intervalo').value) || 1;
    unidade = document.getElementById('unidade').value;
    document.getElementById('acao').value = 'começar';
    document.getElementById('formulario').submit();
}

window.pararLoop = function() {
    document.getElementById('acao').value = 'parar';
    document.getElementById('formulario').submit();
}

window.enviarAutomatico = function() {
    if (!enviando) return;
    let mult = {
        'segundos': 1000,
        'minutos': 60000,
        'horas': 3600000,
        'dias': 86400000,
        'meses': 2592000000,
        'anos': 31536000000
    };
    let delay = intervalo * mult[unidade];
    setTimeout(function loop() {
        if (!enviando) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'enviar_agora=1'
        })
        .then(response => response.text())
        .then(msg => {
            document.getElementById('status').innerHTML = msg;
            setTimeout(loop, delay);
        });
    }, delay);
}

window.onload = function() {
    if (enviando) {
        intervalo = window.rotinaDados['intervalo'] ? parseInt(window.rotinaDados['intervalo']) : 1;
        unidade = window.rotinaDados['unidade'] || 'segundos';
        window.enviarAutomatico();
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'enviar_agora=0'
        })
        .then(response => response.text())
        .then(msg => {
            document.getElementById('status').innerHTML = msg;
        });
    }
}
