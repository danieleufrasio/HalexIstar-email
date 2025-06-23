<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

session_start();
date_default_timezone_set('America/Sao_Paulo');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Recebe o valor do campo, usando filter_input para segurança
    $input = filter_input(INPUT_POST, 'campo', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Aqui você pode fazer o que quiser com o valor, por exemplo, salvar no banco ou exibir
}

// Inicializa as rotinas
if (!isset($_SESSION['rotinas'])) {
    $_SESSION['rotinas'] = [
        [
            'nome' => 'Rotina 1',
            'dados' => [],
            'notificacoes' => [],
            'anexos' => [],
            'datas_agendadas' => [], // datas selecionadas
            'horarios_agendados' => [] // data => hora
        ]
    ];
}
if (!isset($_SESSION['rotina_ativa'])) $_SESSION['rotina_ativa'] = 0;

// Remover rotina
if (isset($_POST['remover_rotina'])) {
    $idx = intval($_POST['remover_rotina']);
    if (isset($_SESSION['rotinas'][$idx])) {
        array_splice($_SESSION['rotinas'], $idx, 1);
        if ($_SESSION['rotina_ativa'] >= count($_SESSION['rotinas'])) {
            $_SESSION['rotina_ativa'] = max(0, count($_SESSION['rotinas']) - 1);
        }
    }
    header('Location: ?rotina=' . $_SESSION['rotina_ativa']);
    exit;
}

// Adiciona nova rotina
if (isset($_POST['nova_rotina']) && trim($_POST['nova_rotina'])) {
    $_SESSION['rotinas'][] = [
        'nome' => htmlspecialchars(trim($_POST['nova_rotina'])),
        'dados' => [],
        'notificacoes' => [],
        'anexos' => [],
        'datas_agendadas' => [],
        'horarios_agendados' => []
    ];
    $_SESSION['rotina_ativa'] = count($_SESSION['rotinas']) - 1;
    header('Location: ?rotina=' . $_SESSION['rotina_ativa']);
    exit;
}

// Troca rotina ativa
if (isset($_GET['rotina'])) {
    $idx = intval($_GET['rotina']);
    if (isset($_SESSION['rotinas'][$idx])) {
        $_SESSION['rotina_ativa'] = $idx;
    }
}

// Backend de cada rotina
$rotina = &$_SESSION['rotinas'][$_SESSION['rotina_ativa']];

// Salva agendamento ao clicar em "Salvar agendamento"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_agendamento') {
    $rotina['dados'] = $_POST;
    $rotina['datas_agendadas'] = array_filter(array_map('trim', explode(',', $_POST['datas_agendadas'] ?? '')));
    $rotina['horarios_agendados'] = $_POST['horarios_agendados'] ?? [];
    $rotina['notificacoes'] = [];
    // Salva anexos temporariamente
    $rotina['anexos'] = [];
    if (!empty($_FILES['anexos']['name'][0])) {
        foreach ($_FILES['anexos']['tmp_name'] as $i => $tmp) {
            if ($_FILES['anexos']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'anx');
                move_uploaded_file($tmp, $tmpFile);
                $rotina['anexos'][] = [
                    'tmp' => $tmpFile,
                    'name' => $_FILES['anexos']['name'][$i]
                ];
            }
        }
    }
}

// ENVIO AUTOMÁTICO: verifica se há envio programado para agora
if (isset($_GET['check_agendamento'])) {
    $dados = $rotina['dados'];
    $email = $dados['email'] ?? '';
    $senha = $dados['senha'] ?? '';
    $assunto = $dados['assunto'] ?? '';
    $mensagem = $dados['mensagem'] ?? '';
    $destinatarios = json_decode($dados['destinatarios_json'] ?? '', true);

    $datas = $rotina['datas_agendadas'] ?? [];
    $horarios = $rotina['horarios_agendados'] ?? [];
    $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $hoje = $agora->format('Y-m-d');
    $horaAgora = $agora->format('H:i');

    $rotina['notificacoes'] = $rotina['notificacoes'] ?? [];
    $enviou = false;

                    foreach ($datas as $data) {
                        if ($data === $hoje && isset($horarios[$data]) && $horaAgora === $horarios[$data]) {
                            // Não enviar novamente se já enviou neste minuto
                            if (!isset($rotina['ultimo_envio']) || $rotina['ultimo_envio'] !== "$data $horaAgora") {
                                if (!empty($email) && !empty($senha) && !empty($destinatarios)) {
                                   foreach ($destinatarios as $dest) {
                    $mail = new PHPMailer(true);
                    try {
                        $mail->CharSet = 'UTF-8'; // Adicione esta linha para garantir UTF-8
                        $mail->isSMTP();
                        $mail->Host = 'smtp-mail.outlook.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = $email;
                        $mail->Password = $senha;
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = 587;

                        $mail->setFrom($email, 'Usuário Outlook');
                        $mail->addAddress($dest['value']);
                        $mail->isHTML(true);

                        // Codifica o assunto para UTF-8, se necessário
                        $mail->Subject = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
                        $mail->Body    = $mensagem;

                        if (!empty($rotina['anexos'])) {
                            foreach ($rotina['anexos'] as $anexo) {
                                $mail->addAttachment($anexo['tmp'], $anexo['name']);
                            }
                        }
                        $mail->send();
                        $rotina['notificacoes'][] = [
                            'status' => 'success',
                            'msg' => "[".date('d/m/Y H:i:s')."] E-mail enviado para <b>{$dest['value']}</b> com sucesso!"
                        ];
                    } catch (Exception $e) {
                        $rotina['notificacoes'][] = [
                            'status' => 'error',
                            'msg' => "[".date('d/m/Y H:i:s')."] Erro ao enviar para <b>{$dest['value']}</b>: {$mail->ErrorInfo}"
                        ];
                    }
                }

                    $rotina['ultimo_envio'] = "$data $horaAgora";
                    $enviou = true;
                }
            }
        }
    }
    // Retorna notificações (para AJAX)
    foreach ($rotina['notificacoes'] as $n) {
        $cor = $n['status'] === 'success' ? 'green' : ($n['status'] === 'error' ? 'red' : 'orange');
        echo "<div style='color:$cor'>{$n['msg']}</div>";
        if ($n['status'] === 'success' && $enviou) {
            echo "<script>setTimeout(function(){alert('E-mail enviado com sucesso!');}, 100);</script>";
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Painel de Rotinas de E-mail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@yaireo/tagify/dist/tagify.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/dubrox/Multiple-Dates-Picker-for-jQuery-UI/jquery-ui.multidatespicker.css">
    <style>
    body {
    background: linear-gradient(120deg, #2c3e50 0%, #fff 100%);
    font-family: 'Segoe UI', Arial, sans-serif;
    margin: 0;
    padding: 0;
}

/* Títulos principais em azul forte */
h1, h2 {
    color: #1565c0 !important;
    font-weight: 800;
    letter-spacing: 0.5px;
    margin-bottom: 18px;
}

/* Painel principal */
.main-panel {
    max-width: 900px;
    margin: 48px auto 0 auto;
    background: #fff;
    border-radius: 22px;
    box-shadow: 0 8px 32px 0 rgba(44,62,80,0.10);
    padding: 44px 36px 36px 36px;
    position: relative;
}

/* Logo centralizado */
#logo {
    display: block;
    margin: 0 auto 32px auto;
    max-width: 200px;
    max-height: 200px;
}

/* Labels em azul forte */
.form-label {
    font-weight: 700;
    color: #1565c0 !important;
    margin-bottom: 6px;
}

/* Campos de formulário */
.form-control, .tagify {
    border-radius: 12px !important;
    font-size: 1.08rem;
    padding: 12px 10px;
    border: 1.5px solid #c5c5c5;
    background: #fff;
    color: #2c3e50 !important;
    margin-bottom: 14px;
    transition: border-color 0.2s;
}
.form-control:focus, .tagify:focus {
    border-color: #1976d2;
    outline: none;
}

/* Tag de email */
.tagify__tag {
    background: #FFF;
    color: #fff;
    font-weight: 700;
    border-radius: 18px;
    margin: 3px 7px 3px 0;
    border: none;
    font-size: 1.05em;
    box-shadow: 0 2px 8px rgba(44,62,80,0.08);
    transition: background 0.15s;
}

/* Tag selecionada sem risco, azul acidentado */
.tagify__tag--selected {
    text-decoration: none !important;
    background: #1976d2 !important;
    color: #fff !important;
    font-weight: bold;
    box-shadow: 0 0 0 2px #1565c0;
}

/* Botão submit azul acidentado */
.btn-success {
    background: #1976d2 !important;
    color: #fff !important;
    border: none;
    border-radius: 14px;
    font-weight: 800;
    font-size: 1.13rem;
    letter-spacing: 0.5px;
    padding: 13px 0;
    box-shadow: 0 2px 12px 0 rgba(44,62,80,0.07);
    transition: background 0.18s, color 0.18s;
}
.btn-success:hover, .btn-success:focus {
    background: #1565c0 !important;
    color: #fff !important;
}

/* Botão de remover tag */
.tagify__tag__removeBtn {
    color: #F00;
    margin-left: 8px;
    font-size: 1.2em;
    opacity: 0.85;
    transition: opacity 0.2s;
}
.tagify__tag__removeBtn:hover {
    opacity: 1;
    background: transparent;
}

/* Botão de ação secundária */
.btn-danger {
    background: #2c3e50;
    color: #fff !important;
    border: none;
    border-radius: 14px;
    font-weight: 800;
    font-size: 1.13rem;
    letter-spacing: 0.5px;
    padding: 13px 0;
    box-shadow: 0 2px 12px 0 rgba(44,62,80,0.07);
    transition: background 0.18s, color 0.18s;
}
.btn-danger:hover, .btn-danger:focus {
    background: #6d2237;
    color: #fff !important;
}

/* Upload customizado */
.custom-file-upload {
    display: inline-block;
    cursor: pointer;
    color: #2c3e50;
    background: #eaeaea;
    border-radius: 10px;
    padding: 10px 18px;
    border: 1px solid #c5c5c5;
    font-weight: 600;
    margin-bottom: 0;
    transition: background 0.18s;
}
.custom-file-upload:hover {
    background: #f3d5d5;
}
.custom-file-upload i {
    margin-right: 8px;
    font-size: 20px;
    vertical-align: middle;
}
input[type="file"] { display: none; }

/* Editor de texto */
.ck-editor__editable_inline {
    min-height: 180px;
    background: #fff;
    border-radius: 10px;
    border: 1.5px solid #c5c5c5;
    margin-bottom: 14px;
}

/* Status */
#status {
    min-height: 40px;
    margin-top: 10px;
}

/* Rodapé */
.footer {
    width: 100%;
    background: #2c3e50;
    color: #fff;
    font-size: 1.02rem;
    font-weight: 500;
    text-align: center;
    padding: 18px 0 16px 0;
    left: 0;
    bottom: 0;
    z-index: 100;
    letter-spacing: 0.4px;
}

/* Tabs */
.nav-tabs .nav-link.active {
    background:#1565c0;
    color: #fff !important;
    font-weight: bold;
}
.nav-tabs .nav-link {
    color: #2c3e50;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.4em;
    padding-right: 2.2em;
}

/* Botão adicionar */
.btn-add {
    background: #2c3e50;
    color: #fff;
    border-radius: 50%;
    width: 38px;
    height: 38px;
    font-size: 1.5em;
    line-height: 1;
    transition: background 0.18s;
}
.btn-add:hover {
    background: #6d2237;
    color: #fff;
}

/* Responsividade */
@media (max-width: 1000px) {
    .main-panel { padding: 30px 8px 24px 8px; }
}

/* Fechar aba */
.nav-item { position: relative; }
.tab-close-btn {
    background: transparent;
    border: none;
    padding: 0;
    margin-left: 0.1em;
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    outline: none;
    cursor: pointer;
    z-index: 2;
}
.tab-close-circle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #a71d2a;
    color: #fff;
    transition: background 0.2s, box-shadow 0.2s;
    font-size: 1.1em;
}
.tab-close-btn:hover .tab-close-circle,
.tab-close-btn:focus .tab-close-circle {
    background: #7b1621;
    box-shadow: 0 0 0 2px #a71d2a;
}
.tab-close-circle svg {
    width: 14px;
    height: 14px;
    display: block;
    pointer-events: none;
}

/* Campo de hora/data */
.hora-data {
    margin-left: 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
    padding: 2px 8px;
}
</style>
</head>
<body>
<div class="main-panel">
    <img src="assets/img/logo.png" id="logo" alt="Logo" />
    <h2 class="mb-4 text-center" style="color:#6d2237;">Painel de Rotinas de E-mail</h2>
    <ul class="nav nav-tabs mb-3" id="rotinasTabs" role="tablist">
        <?php foreach ($_SESSION['rotinas'] as $i => $r): ?>
            <li class="nav-item" role="presentation" style="display:flex; align-items:center; position:relative; padding-right: 0.5rem;">
                <a class="nav-link<?= $_SESSION['rotina_ativa'] === $i ? ' active' : '' ?>"
                   href="?rotina=<?= $i ?>"
                   id="tab-<?= $i ?>" role="tab"
                   aria-selected="<?= $_SESSION['rotina_ativa'] === $i ? 'true' : 'false' ?>">
                    <?= htmlspecialchars($r['nome']) ?>
                </a>
                <?php if (count($_SESSION['rotinas']) > 1): ?>
                    <form method="post" style="margin:0; padding:0;">
                        <input type="hidden" name="remover_rotina" value="<?= $i ?>">
                        <button type="submit"
                            class="tab-close-btn"
                            aria-label="Fechar rotina <?= htmlspecialchars($r['nome']) ?>"
                            title="Fechar rotina"
                            tabindex="0">
                            <span class="tab-close-circle">
                                <svg viewBox="0 0 16 16" fill="none">
                                    <path d="M4 4L12 12M12 4L4 12" stroke="white" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </span>
                        </button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li class="nav-item" style="display:flex; align-items:center;">
            <form method="post" style="display:inline;">
                <input type="text" name="nova_rotina" placeholder="+" style="width:38px; border:none; background:transparent; text-align:center; font-size:1.5em;" required aria-label="Adicionar nova rotina">
            </form>
        </li>
    </ul>
    <form method="post" id="formulario" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="acao" id="acao" value="salvar_agendamento">
        <input type="hidden" name="destinatarios_json" id="destinatarios_json" value="<?= htmlspecialchars($rotina['dados']['destinatarios'] ?? '') ?>">
        <div class="mb-3">
            <label class="form-label" for="destinatarios">Para (digite e-mails, pressione Enter, vírgula ou ;):</label>
            <input name="destinatarios" id="destinatarios" placeholder="Digite e-mails e pressione Enter">
        </div>
        <div class="mb-3">
            <label class="form-label">Seu e-mail Outlook:</label>
            <input type="email" class="form-control" name="email" required value="<?= htmlspecialchars($rotina['dados']['email'] ?? '') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Sua senha:</label>
            <input type="password" class="form-control" name="senha" required value="<?= htmlspecialchars($rotina['dados']['senha'] ?? '') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Assunto:</label>
            <input type="text" class="form-control" name="assunto" value="<?= htmlspecialchars($rotina['dados']['assunto'] ?? 'Teste Outlook') ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Mensagem (aceita imagens):</label>
            <textarea id="editor" name="mensagem"><?= htmlspecialchars($rotina['dados']['mensagem'] ?? 'Mensagem de teste') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="custom-file-upload">
                <i class="bi bi-paperclip"></i> Escolher arquivos
                <input type="file" id="anexos" name="anexos[]" multiple>
            </label>
        </div>
        <div class="mb-3">
            <label class="form-label">Escolha as datas para envio:</label>
            <input type="text" id="datas_agendadas" class="form-control" autocomplete="off" readonly>
            <input type="hidden" name="datas_agendadas" id="datas_agendadas_hidden" value="<?= htmlspecialchars(implode(',', $rotina['datas_agendadas'] ?? [])) ?>">
            <div id="horarios_agendados"></div>
            <small class="text-muted">Selecione as datas no calendário e defina o horário para cada uma.</small>
        </div>
        <div class="d-grid gap-2 mt-3">
            <button type="submit" class="btn btn-success">Salvar agendamento</button>
        </div>
    </form>
    <div id="status" class="mt-3">
        <?php
        if (!empty($rotina['notificacoes'])) {
            foreach ($rotina['notificacoes'] as $n) {
                $cor = $n['status'] === 'success' ? 'green' : ($n['status'] === 'error' ? 'red' : 'orange');
                echo "<div style='color:$cor'>{$n['msg']}</div>";
                if ($n['status'] === 'success') {
                    echo "<script>setTimeout(function(){alert('E-mail enviado com sucesso!');}, 100);</script>";
                }
            }
        }
        ?>
    </div>
</div>
<footer class="footer">
    Todos os direitos reservados Halexistar | Daniel Eufrasio
</footer>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/dubrox/Multiple-Dates-Picker-for-jQuery-UI/jquery-ui.multidatespicker.js"></script>
 <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>
<!-- Bibliotecas JS necessárias -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/dubrox/Multiple-Dates-Picker-for-jQuery-UI/jquery-ui.multidatespicker.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>

<!-- Bibliotecas JS necessárias -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/dubrox/Multiple-Dates-Picker-for-jQuery-UI/jquery-ui.multidatespicker.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>

<script>
$(function () {
    // Tagify para destinatários
    const destinatariosInput = document.querySelector('input[name=destinatarios]');
    const destinatariosJsonInput = document.getElementById('destinatarios_json');
    const tagify = new Tagify(destinatariosInput, {
        delimiters: ",; ",
        pattern: /^[\w\.-]+@[\w\.-]+\.\w{2,}$/,
        whitelist: [],
        dropdown: { enabled: 0 },
        editTags: false,
        duplicates: false
    });

    // Preenche tags se já houver destinatários
    <?php if (!empty($rotina['dados']['destinatarios'])): ?>
        tagify.addTags(JSON.parse(<?= json_encode($rotina['dados']['destinatarios']) ?>));
    <?php endif; ?>

    // Validação de campo ao submeter formulário
    $('#meuFormulario').on('submit', function (e) {
        const valor = $('#campo').val();
        // Regex Unicode para aceitar todos os caracteres acentuados e especiais comuns
        const regex = /^[\wÀ-ÿ\s.,;:!@#$%^&*()_+\-=\[\]{}'"<>?\\/|~`´ºª°çÇ]+$/u;
        if (!regex.test(valor)) {
            e.preventDefault();
            $('#mensagemErro').text('Por favor, digite apenas caracteres válidos!').show();
            $('#campo').focus();
        } else {
            $('#mensagemErro').hide();
        }
        // Atualiza campo oculto com os destinatários em JSON antes de enviar
        destinatariosJsonInput.value = JSON.stringify(tagify.value);
    });

    // CKEditor
    ClassicEditor.create(document.querySelector('#editor'), {
        toolbar: [
            'heading', '|', 'bold', 'italic', 'underline', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'insertTable', 'imageUpload', 'undo', 'redo'
        ],
        image: {
            toolbar: [ 'imageTextAlternative', 'imageStyle:full', 'imageStyle:side' ]
        }
    });

    // MultiDatesPicker para datas agendadas
    let datasSelecionadas = <?= json_encode($rotina['datas_agendadas'] ?? []) ?>;
    let horariosAgendados = <?= json_encode($rotina['horarios_agendados'] ?? []) ?>;

    function renderHorariosAgendados() {
        let html = '';
        datasSelecionadas.forEach(date => {
            let value = horariosAgendados[date] || '09:00';
            html += `<div style="margin-bottom:6px;">
                <span>${date}</span>
                <input type="time" name="horarios_agendados[${date}]" value="${value}" class="hora-data" style="margin-left:10px;">
            </div>`;
        });
        document.getElementById('horarios_agendados').innerHTML = html;
    }

    $("#datas_agendadas").multiDatesPicker({
        dateFormat: 'yy-mm-dd',
        onSelect: function () {
            datasSelecionadas = $("#datas_agendadas").multiDatesPicker('getDates');
            $("#datas_agendadas_hidden").val(datasSelecionadas.join(','));
            renderHorariosAgendados();
        }
    });
    $("#datas_agendadas").multiDatesPicker('addDates', datasSelecionadas);
    $("#datas_agendadas_hidden").val(datasSelecionadas.join(','));
    renderHorariosAgendados();

    // Checagem automática de agendamento
    function checkAgendamento() {
        fetch('?check_agendamento=1')
            .then(response => response.text())
            .then(msg => {
                document.getElementById('status').innerHTML = msg;
                setTimeout(checkAgendamento, 60000); // checa a cada minuto
            });
    }
    checkAgendamento();
});
</script>
</body>
</html>
