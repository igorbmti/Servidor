<?php
session_start();

// Se já estiver logado, redireciona diretamente ao painel
if (!empty($_SESSION['usuario_logado'])) {
    header('Location: index.php');
    exit;
}

$erro = '';

// Credenciais autorizadas
$usuariosAutorizados = [
    'igorf' => ['nome' => 'Igorf', 'senha' => '968574'],
    'karla' => ['nome' => 'Karla', 'senha' => '847913']
];

// Processamento de login via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $un = trim($_POST['un'] ?? '');
    $pw = trim($_POST['pw'] ?? '');
    $unLower = strtolower($un);

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if (isset($usuariosAutorizados[$unLower]) && $usuariosAutorizados[$unLower]['senha'] === $pw) {
        session_regenerate_id(true);
        $_SESSION['usuario_logado'] = $usuariosAutorizados[$unLower]['nome'];
        $_SESSION['logado_em'] = time();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso' => true, 'redirect' => 'index.php']);
            exit;
        }

        header('Location: index.php');
        exit;
    } else {
        $erro = 'Credenciais incorretas. Verifique o usuário e a senha.';

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'erro' => $erro]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login • Painel de Controle NEXUS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        :root {
            --bg-principal: #0B0F0D;
            --bg-secundario: #111714;
            --bg-elevado: #17201B;
            --borda: #26352B;
            --brand: #22C55E;
            --brand-glow: rgba(34, 197, 94, 0.3);
            --ink: #ECFDF5;
            --ink-sec: #94A3B8;
            --ink-muted: #64748B;
            --vermelho: #EF4444;
            --amarelo: #F59E0B;
            --azul-win: #38BDF8;
            --azul-ps4: #0070D1;
            --amarelo-lin: #FCC624;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            width: 100%;
            min-height: 100vh;
            background: radial-gradient(circle at 50% 35%, #131A15, #080B0A 80%);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            position: relative;
        }

        /* Linhas sutis de grade no fundo tecnológico */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                linear-gradient(rgba(38, 53, 43, 0.12) 1px, transparent 1px),
                linear-gradient(90deg, rgba(38, 53, 43, 0.12) 1px, transparent 1px);
            background-size: 32px 32px;
            pointer-events: none;
            z-index: 0;
        }

        /* CAIXA DE LOGIN ESTILIZADA NAS CORES DO SISTEMA */
        .login-wrap {
            position: relative;
            margin: 0 auto;
            background: rgba(17, 23, 20, 0.94);
            width: 360px;
            max-width: 90vw;
            border-radius: 14px;
            border: 1px solid var(--borda);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.9), 0 0 1px rgba(34, 197, 94, 0.35);
            padding: 28px 24px 22px 24px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 1;
            transition: all 0.3s ease;
        }

        /* Barra decorativa superior harmonizada com as cores dos equipamentos da rede */
        .login-wrap::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, 
                var(--brand) 0%, var(--brand) 25%, 
                var(--azul-win) 25%, var(--azul-win) 50%, 
                var(--azul-ps4) 50%, var(--azul-ps4) 75%, 
                var(--amarelo-lin) 75%, var(--amarelo-lin) 100%
            );
            border-radius: 14px 14px 0 0;
            box-shadow: 0 0 12px var(--brand-glow);
        }

        .login-badge-topo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .net-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 10px var(--brand);
        }

        .net-tag {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--brand);
        }

        .login-wrap h2 {
            text-align: center;
            font-weight: 700;
            font-size: 1.75em;
            letter-spacing: -0.5px;
            color: var(--ink);
            margin-bottom: 4px;
        }

        .login-sub {
            text-align: center;
            font-size: 12px;
            color: var(--ink-muted);
            margin-bottom: 20px;
        }

        .form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .form input[type="text"],
        .form input[type="password"] {
            width: 100%;
            height: 42px;
            border-radius: 8px;
            outline: none;
            background: rgba(23, 32, 27, 0.9);
            border: 1px solid var(--borda);
            padding: 0 14px;
            font-size: 13.5px;
            color: var(--ink);
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .form input[type="text"]:focus,
        .form input[type="password"]:focus {
            border-color: var(--brand);
            background: rgba(23, 32, 27, 1);
            box-shadow: 0 0 12px rgba(34, 197, 94, 0.35);
        }

        .form input::placeholder {
            color: var(--ink-muted);
            font-size: 13px;
        }

        .form button {
            width: 100%;
            height: 44px;
            background: var(--brand);
            border: none;
            border-radius: 8px;
            color: #080B0A;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 4px;
            box-shadow: 0 0 20px -2px rgba(34, 197, 94, 0.35);
        }

        .form button:hover {
            background: #16a34a;
            box-shadow: 0 0 25px rgba(34, 197, 94, 0.55);
            transform: translateY(-1px);
        }

        .form button:active {
            transform: scale(0.99);
        }

        .form button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .form a {
            text-align: center;
            font-size: 11.5px;
            color: var(--brand);
            text-decoration: none;
            transition: color 0.2s;
            display: block;
            margin-top: 6px;
        }

        .form a:hover {
            color: #4ade80;
            text-decoration: underline;
        }

        .form a p {
            padding: 4px 0;
            cursor: pointer;
        }

        /* ALERTA DE ERRO / MENSAGENS */
        .msg-box {
            display: none;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.4;
            text-align: center;
            margin-bottom: 12px;
            animation: fadeInMsg 0.3s ease;
        }

        .msg-box.erro {
            display: block;
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #F87171;
        }

        .msg-box.aviso {
            display: block;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: var(--amarelo);
        }

        .msg-box.sucesso {
            display: block;
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: var(--brand);
        }

        @keyframes fadeInMsg {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-footer {
            margin-top: 18px;
            text-align: center;
            font-size: 10.5px;
            color: var(--ink-muted);
            border-top: 1px solid rgba(38, 53, 43, 0.5);
            padding-top: 12px;
        }

        .login-footer .mono {
            font-family: 'JetBrains Mono', monospace;
            color: var(--brand);
        }
    </style>
</head>
<body>

    <div class="login-wrap">
        <div class="login-badge-topo">
            <span class="net-dot"></span>
            <span class="net-tag">Controle de Rede</span>
        </div>

        <h2>Login</h2>
        <p class="login-sub">Painel de Monitoramento • Autenticação</p>

        <div id="msg-box" class="msg-box <?= !empty($erro) ? 'erro' : '' ?>">
            <?= !empty($erro) ? htmlspecialchars($erro) : '' ?>
        </div>

        <form id="form-login" class="form" method="POST" action="login.php" autocomplete="on">
            <input type="text" placeholder="Username" name="un" id="un" required autocomplete="username" />
            <input type="password" placeholder="Password" name="pw" id="pw" required autocomplete="current-password" />
            <button type="submit" id="btn-submit">
                <span>Sign in</span>
            </button>
            <a href="#" id="link-toggle">
                <p>Don't have an account? Register</p>
            </a>
        </form>

        <div class="login-footer">
            Acesso Restrito • Rede Local <span class="mono">192.168.18.166</span>
        </div>
    </div>

    <script>
    // Usuários cadastrados no sistema
    var users = [
        { name: 'Igorf' },
        { name: 'igorf' },
        { name: 'Karla' },
        { name: 'karla' }
    ];

    var loginform = {
        init: function() {
            this.bindUserBox();
            this.bindFormEvents();
        },

        bindUserBox: function() {
            var result = [];

            $(".form").on("blur", "input[name='un']", function() {
                var $self = $(this);
                var val = ($self.val() || "").trim();

                if (!val) return;

                // Verifica se o usuário já existe na lista
                result = $.grep(users, function(elem) {
                    return elem.name.toLowerCase() === val.toLowerCase();
                });

                if (result.length > 0) {
                    if ($("div.login-wrap").hasClass('register')) {
                        loginform.revertForm();
                    }
                } else {
                    if (!$("div.login-wrap").hasClass('register') && val.length > 4) {
                        loginform.switchForm();
                    }
                }
            });
        },

        switchForm: function() {
            var $wrap = $("div.login-wrap").addClass('register');
            $wrap.find('h2').html('Register');
            if ($(".form input[name='rpw']").length === 0) {
                $(".form input[name='pw']").after("<input type='password' placeholder='Re-type password' name='rpw' id='rpw' />");
            }
            $wrap.find('button span').html('Sign up');
            $wrap.find('a p').html('Have an account? Sign in');
            $('#msg-box').removeClass('erro sucesso').addClass('aviso').text('Usuário não localizado na lista de operadores. Preencha os campos para verificação.').show();
        },

        revertForm: function() {
            var $wrap = $("div.login-wrap").removeClass('register');
            $wrap.find('h2').html('Login');
            $(".form input[name='rpw']").remove();
            $wrap.find('button span').html('Sign in');
            $wrap.find('a p').html("Don't have an account? Register");
            $('#msg-box').hide().text('').removeClass('aviso erro sucesso');
        },

        bindFormEvents: function() {
            // Alternância manual ao clicar no link
            $('#link-toggle').on('click', function(e) {
                e.preventDefault();
                if ($("div.login-wrap").hasClass('register')) {
                    loginform.revertForm();
                } else {
                    loginform.switchForm();
                }
            });

            // Envio do formulário com validação e feedback interativo
            $('#form-login').on('submit', function(e) {
                e.preventDefault();

                var isRegister = $("div.login-wrap").hasClass('register');
                var un = $('#un').val().trim();
                var pw = $('#pw').val();

                if (isRegister) {
                    var rpw = $('#rpw').val();
                    if (!pw || pw !== rpw) {
                        $('#msg-box').removeClass('sucesso aviso').addClass('erro').text('As senhas digitadas não coincidem.').show();
                        return;
                    }
                    $('#msg-box').removeClass('sucesso erro').addClass('aviso')
                        .text('Registro restrito ao administrador de rede. Utilize as contas autorizadas (Igorf ou Karla).').show();
                    return;
                }

                var $btn = $('#btn-submit');
                $btn.prop('disabled', true).find('span').text('Autenticando...');

                $.ajax({
                    url: 'login.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { un: un, pw: pw },
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    success: function(resp) {
                        if (resp && resp.sucesso) {
                            $('#msg-box').removeClass('erro aviso').addClass('sucesso').text('Login efetuado com sucesso! Redirecionando...').show();
                            setTimeout(function() {
                                window.location.href = resp.redirect || 'index.php';
                            }, 400);
                        } else {
                            $btn.prop('disabled', false).find('span').text('Sign in');
                            $('#msg-box').removeClass('sucesso aviso').addClass('erro').text(resp.erro || 'Falha na autenticação.').show();
                        }
                    },
                    error: function(xhr) {
                        $btn.prop('disabled', false).find('span').text('Sign in');
                        var msg = 'Usuário ou senha incorretos.';
                        if (xhr.responseJSON && xhr.responseJSON.erro) {
                            msg = xhr.responseJSON.erro;
                        }
                        $('#msg-box').removeClass('sucesso aviso').addClass('erro').text(msg).show();
                    }
                });
            });
        }
    };

    // Inicialização
    loginform.init();

    // Centralização dinâmica complementar mantendo compatibilidade com o script original
    function centralizarLogin() {
        var $wrap = $(".login-wrap");
        if ($(window).height() > $wrap.outerHeight() + 40) {
            var margem = Math.max(20, Math.floor(($(window).height() / 2) - ($wrap.outerHeight() / 2)));
            $wrap.css("margin-top", margem + "px");
        } else {
            $wrap.css("margin-top", "20px");
        }
    }

    $(document).ready(centralizarLogin);
    $(window).on('resize', centralizarLogin);
    </script>
</body>
</html>
