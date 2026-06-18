#!/bin/bash

set -e

echo "[+] Instalando Apache + PHP..."
apt update
apt install -y apache2 php libapache2-mod-php sudo
echo "[+] Criando diretório do painel..."
mkdir -p /var/www/html/painel

echo "[+] Criando index.php..."

cat > /var/www/html/painel/index.php <<'EOF'
<?php

$file = "/var/cache/bind/master-aut/conectanetwork.net.br.hosts";

if ($_POST) {
    file_put_contents($file, $_POST['zone']);
    exec("sudo rndc reload");
}

$zone = file_exists($file) ? file_get_contents($file) : "Arquivo não encontrado.";

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Painel DNS</title>
<style>
body {
    font-family: Arial;
    background: #0f172a;
    color: #e2e8f0;
    padding: 20px;
}
h2 {
    color: #38bdf8;
}
textarea {
    width: 100%;
    height: 500px;
    background: #020617;
    color: #22c55e;
    border: 1px solid #1e293b;
    padding: 10px;
    font-family: monospace;
}
button {
    padding: 12px 20px;
    background: #0ea5e9;
    border: none;
    color: white;
    cursor: pointer;
    font-weight: bold;
}
button:hover {
    background: #0284c7;
}
</style>
</head>

<body>

<h2>🚀 Painel DNS (BIND)</h2>

<form method="POST">
<textarea name="zone"><?= htmlspecialchars($zone) ?></textarea>
<br><br>
<button type="submit">Salvar + Reload</button>
</form>

</body>
</html>
EOF

echo "[+] Ajustando permissões..."

chown -R www-data:www-data /var/www/html/painel

# Permitir escrita na zona (cuidado: ambiente controlado)
chown -R bind:www-data /var/cache/bind/master-aut
chmod -R 775 /var/cache/bind/master-aut

echo "[+] Configurando sudo para rndc..."

echo "www-data ALL=(ALL) NOPASSWD: /usr/sbin/rndc reload" > /etc/sudoers.d/rndc
chmod 440 /etc/sudoers.d/rndc

echo "[+] Reiniciando Apache..."
systemctl restart apache2

echo ""
echo "[✔] Painel instalado!"
echo ""
echo "Acesse:"
echo "http://SEU-IP/painel"
