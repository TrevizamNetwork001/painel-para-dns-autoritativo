<?php
session_start();
require_once __DIR__ . "/includes/users.php";
require_once __DIR__ . "/includes/security.php";
require_once __DIR__ . "/includes/audit.php";

$erro = null;

if (isset($_GET['timeout'])) {
    $erro = "Sessão expirada por inatividade";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $user = trim((string) ($_POST['user'] ?? ''));
    $pass = (string) ($_POST['pass'] ?? '');
    $registro = usuario_por_login($user);

    if (
        $registro
        && (int) $registro['ativo'] === 1
        && password_verify($pass, $registro['senha_hash'])
    ) {
        if (password_needs_rehash($registro['senha_hash'], PASSWORD_DEFAULT)) {
            $stmt = db()->prepare("
                UPDATE usuarios
                SET senha_hash = :senha_hash, atualizado_em = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ':senha_hash' => password_hash($pass, PASSWORD_DEFAULT),
                ':id' => $registro['id'],
            ]);
        }

        session_regenerate_id(true);
        $_SESSION['logado'] = true;
        $_SESSION['ultimo_acesso'] = time();
        $_SESSION['usuario_id'] = (int) $registro['id'];
        $_SESSION['usuario'] = $registro['usuario'];
        $_SESSION['perfil'] = $registro['perfil'];
        $_SESSION['trocar_senha'] = (int) $registro['trocar_senha'];
        $_SESSION['auth_version'] = (int) $registro['auth_version'];

        try {
            registrar_auditoria([
                'usuario' => $registro['usuario'],
                'acao' => 'LOGIN_SUCESSO',
                'status' => 'OK',
                'mensagem' => 'Login realizado com sucesso',
            ]);
        } catch (Throwable $e) {
            error_log('Falha ao registrar LOGIN_SUCESSO: ' . $e->getMessage());
        }

        header('Location: ' . ($registro['trocar_senha'] ? 'alterar-senha.php' : 'dashboard.php'));
        exit;
    } else {
        try {
            registrar_auditoria([
                'usuario' => $user !== '' ? $user : 'não informado',
                'acao' => 'LOGIN_FALHA',
                'status' => 'ERRO',
                'mensagem' => 'Usuário ou senha inválidos',
            ]);
        } catch (Throwable $e) {
            error_log('Falha ao registrar LOGIN_FALHA: ' . $e->getMessage());
        }

        $erro = "Usuário ou senha inválidos";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Login</title>

<style>
#particles {
    position: fixed;
    width: 100%;
    height: 100%;
    z-index: -1;
}

body {
    margin:0;
    font-family:Arial;
    background:#0f172a;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    color:white;
}

.card {
    background:#020617;
    padding:30px;
    border-radius:10px;
    width:300px;
    box-shadow:0 0 20px rgba(0,0,0,0.5);
}

h2 {
    text-align:center;
    color:#38bdf8;
}

.sub {
    text-align:center;
    font-size:13px;
    color:#94a3b8;
    margin-top:5px;
}

input {
    width:100%;
    padding:10px;
    margin-top:10px;
    border-radius:5px;
    border:1px solid #334155;
    background:#020617;
    color:white;
}

button {
    width:100%;
    margin-top:15px;
    padding:10px;
    background:#3b82f6;
    border:none;
    border-radius:6px;
    color:white;
    cursor:pointer;
}

.alert {
    background:#dc2626;
    padding:10px;
    border-radius:6px;
    margin-bottom:10px;
    text-align:center;
}
</style>
</head>

<body>

<div id="particles"></div>

<div class="card">

<h2>🔐 Login</h2>
<p class="sub">Gerenciamento de DNS Autoritativo</p>

<?php if ($erro): ?>
<div id="alerta" class="alert"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<form method="POST" id="loginForm">
<?= csrf_field() ?>
<input type="text" name="user" placeholder="Usuário" required>
<input type="password" name="pass" placeholder="Senha" required>
<button id="btnLogin">Entrar</button>
</form>

</div>

<script>
// some sozinho (3s)
setTimeout(function() {
    var el = document.getElementById('alerta');
    if (el) {
        el.style.transition = "opacity 0.5s";
        el.style.opacity = "0";
        setTimeout(() => el.remove(), 500);
    }
}, 3000);

// some ao digitar
document.querySelectorAll("input").forEach(input => {
    input.addEventListener("input", () => {
        let el = document.getElementById("alerta");
        if (el) el.remove();
    });
});
</script>
<script>
document.getElementById("loginForm").addEventListener("submit", function(e) {

    e.preventDefault();

    let btn = document.getElementById("btnLogin");
    btn.innerHTML = "⏳ Entrando...";
    btn.disabled = true;

    setTimeout(() => {
        this.submit();
    }, 500); // aumenta aqui
});
</script>
<script src="https://cdn.jsdelivr.net/npm/tsparticles@2/tsparticles.bundle.min.js"></script>

<script>
tsParticles.load("particles", {
    background: {
        color: "#0f172a"
    },
    particles: {
        number: { value: 50 },
        color: { value: "#fb923c" },
        links: {
            enable: true,
            distance: 150,
            color: "#fb923c",
            opacity: 0.4,
            width: 1
        },
        move: {
            enable: true,
            speed: 0.6
        },
        size: {
            value: 2
        }
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
