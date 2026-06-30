#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

PACKAGE="/tmp/painel-bind-clean.tar.gz"
TARGET="/var/www/html/painel"
SERVER_NAME="localhost"
APACHE_SITE_NAME="painel-bind.conf"
WITH_FIREWALL=0
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
LOG="/var/log/painel-bind-install.log"

usage() {
    cat <<'USAGE'
Uso: sudo bash scripts/install.sh [opcoes]

Opcoes:
  --package CAMINHO       Pacote .tar.gz gerado por git archive
  --target CAMINHO        Diretorio de instalacao
  --server-name NOME      ServerName do VirtualHost (default: localhost)
  --apache-site NOME      Nome do arquivo do site Apache (default: painel-bind.conf)
  --with-firewall         Exige nft instalado para operacao posterior manual
  -h, --help              Mostra esta ajuda
USAGE
}

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"
}

die() {
    log "ERRO: $*"
    exit 1
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "dependencia ausente: $1"
}

cleanup() {
    if [ -n "${TMPDIR_INSTALL:-}" ] && [ -d "$TMPDIR_INSTALL" ]; then
        rm -rf "$TMPDIR_INSTALL"
    fi
}
trap cleanup EXIT

while [ "$#" -gt 0 ]; do
    case "$1" in
        --package)
            [ "$#" -ge 2 ] || die "--package exige um caminho"
            PACKAGE="$2"
            shift 2
            ;;
        --target)
            [ "$#" -ge 2 ] || die "--target exige um caminho"
            TARGET="$2"
            shift 2
            ;;
        --server-name)
            [ "$#" -ge 2 ] || die "--server-name exige um nome"
            SERVER_NAME="$2"
            shift 2
            ;;
        --apache-site)
            [ "$#" -ge 2 ] || die "--apache-site exige um nome"
            APACHE_SITE_NAME="$2"
            shift 2
            ;;
        --with-firewall)
            WITH_FIREWALL=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "opcao desconhecida: $1"
            ;;
    esac
done

APACHE_SITE="/etc/apache2/sites-available/$APACHE_SITE_NAME"
APACHE_ENABLED="/etc/apache2/sites-enabled/$APACHE_SITE_NAME"

if [ "$(id -u)" -ne 0 ]; then
    printf 'Este instalador deve ser executado como root.\n' >&2
    exit 1
fi

if ! touch "$LOG" 2>/dev/null; then
    LOG="/tmp/painel-bind-install-$TIMESTAMP.log"
    touch "$LOG" || {
        printf 'Nao foi possivel criar log de instalacao.\n' >&2
        exit 1
    }
fi
chmod 640 "$LOG" || true

validate_dependencies() {
    log "Validando dependencias"
    need_cmd php
    need_cmd sqlite3
    need_cmd apache2
    need_cmd apachectl
    need_cmd tar
    need_cmd curl
    need_cmd openssl
    need_cmd systemctl

    php -m | grep -Eiq '^(sqlite3|pdo_sqlite)$' || die "extensao SQLite do PHP ausente"

    if ! command -v named-checkconf >/dev/null 2>&1; then
        if command -v dpkg >/dev/null 2>&1; then
            dpkg -s bind9 >/dev/null 2>&1 || die "named-checkconf ausente e pacote bind9 nao encontrado"
        else
            die "named-checkconf ausente"
        fi
    fi

    if [ "$WITH_FIREWALL" -eq 1 ]; then
        need_cmd nft
    fi
}

validate_package() {
    log "Validando pacote $PACKAGE"
    [ -f "$PACKAGE" ] || die "pacote nao encontrado: $PACKAGE"
    tar -tzf "$PACKAGE" >/dev/null || die "pacote invalido ou corrompido"

    local bad
    bad="$(
        tar -tzf "$PACKAGE" \
            | grep -Ei '(^|/)(\.env$|.*\.sqlite$|.*\.db$|.*\.secret$|.*\.pem$|.*\.key$|.*\.bak$|.*\.bkp$|.*\.backup$|.*\.save$|.*\.log$|.*\.tar\.gz$|.*\.zip$|storage/database/.*|storage/secrets/.*|storage/logs/.*|storage/backups/.*|storage/cache/.*)' \
            | grep -Ev '(^|/)storage/(database|secrets|logs|backups|cache)/\.gitkeep$' || true
    )"
    [ -z "$bad" ] || die "pacote contem arquivos proibidos: $(printf '%s' "$bad" | tr '\n' ' ')"

    tar -tzf "$PACKAGE" | grep -qx 'db/schema.sql' || die "db/schema.sql ausente no pacote"
    tar -tzf "$PACKAGE" | grep -qx 'scripts/install.sh' || die "scripts/install.sh ausente no pacote"
    tar -tzf "$PACKAGE" | grep -qx 'public/index.php' || die "public/index.php ausente no pacote"
    tar -tzf "$PACKAGE" | grep -qx 'public/login.php' || die "public/login.php ausente no pacote"
}

confirm_empty_install_if_needed() {
    if [ -d "$TARGET" ] && [ -n "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        printf 'O diretorio alvo ja existe e nao esta vazio: %s\n' "$TARGET"
        printf 'Esta instalacao zerada substituira o conteudo do alvo, sem reaproveitar storage antigo.\n'
        printf 'Digite INSTALAR_ZERADO para continuar: '
        read -r confirmation
        [ "$confirmation" = "INSTALAR_ZERADO" ] || die "confirmacao invalida"
    fi
}

backup_apache_config() {
    if [ -f "$APACHE_SITE" ]; then
        cp -a "$APACHE_SITE" "$APACHE_SITE.bak-$TIMESTAMP"
        log "Backup Apache criado: $APACHE_SITE.bak-$TIMESTAMP"
    fi

    if [ -L "$APACHE_ENABLED" ] || [ -e "$APACHE_ENABLED" ]; then
        log "Site Apache ja habilitado em $APACHE_ENABLED"
    fi
}

extract_release() {
    log "Extraindo release limpo para $TARGET"
    TMPDIR_INSTALL="$(mktemp -d /tmp/painel-bind-install.XXXXXX)"
    tar -xzf "$PACKAGE" -C "$TMPDIR_INSTALL"

    mkdir -p "$TARGET"
    find "$TARGET" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    cp -a "$TMPDIR_INSTALL"/. "$TARGET"/
}

create_storage() {
    log "Criando estrutura storage"
    mkdir -p "$TARGET/storage/database" "$TARGET/storage/secrets" "$TARGET/storage/logs" "$TARGET/storage/backups" "$TARGET/storage/cache"
    touch "$TARGET/storage/database/.gitkeep" "$TARGET/storage/secrets/.gitkeep" "$TARGET/storage/logs/.gitkeep" "$TARGET/storage/backups/.gitkeep" "$TARGET/storage/cache/.gitkeep"
}

create_database() {
    local db="$TARGET/storage/database/painel_dns.sqlite"
    local schema="$TARGET/db/schema.sql"

    [ -f "$schema" ] || die "schema nao encontrado: $schema"
    if [ -e "$db" ]; then
        die "banco ja existe apos extracao: $db"
    fi

    log "Criando banco SQLite novo"
    sqlite3 "$db" < "$schema"
    [ "$(sqlite3 "$db" 'PRAGMA integrity_check;')" = "ok" ] || die "integrity_check do SQLite falhou"
}

valid_password() {
    local password="$1"
    [ "${#password}" -ge 8 ] || return 1
    [[ "$password" =~ [A-Z] ]] || return 1
    [[ "$password" =~ [a-z] ]] || return 1
    [[ "$password" =~ [0-9] ]] || return 1
    [[ "$password" =~ [^A-Za-z0-9] ]] || return 1
}

create_admin_user() {
    local admin_user admin_pass admin_confirm admin_hash db
    db="$TARGET/storage/database/painel_dns.sqlite"

    printf 'Usuario admin inicial: '
    read -r admin_user
    [ -n "$admin_user" ] || die "usuario admin vazio"

    printf 'Senha admin inicial: '
    read -r -s admin_pass
    printf '\n'
    printf 'Confirmar senha admin inicial: '
    read -r -s admin_confirm
    printf '\n'

    [ "$admin_pass" = "$admin_confirm" ] || die "senhas nao conferem"
    valid_password "$admin_pass" || die "senha deve ter 8+ caracteres, maiuscula, minuscula, numero e caractere especial"

    admin_hash="$(printf '%s' "$admin_pass" | php -r '$p = stream_get_contents(STDIN); echo password_hash($p, PASSWORD_DEFAULT);')"
    unset admin_pass admin_confirm

    ADMIN_USER="$admin_user" ADMIN_HASH="$admin_hash" DB_PATH="$db" php <<'PHP'
<?php
$dbPath = getenv('DB_PATH');
$user = getenv('ADMIN_USER');
$hash = getenv('ADMIN_HASH');
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $pdo->prepare("INSERT INTO usuarios (usuario, senha_hash, perfil, ativo, trocar_senha) VALUES (:usuario, :senha_hash, 'administrador', 1, 0)");
$stmt->execute([
    ':usuario' => $user,
    ':senha_hash' => $hash,
]);
PHP
    unset admin_hash
    log "Admin inicial criado"
}

create_secret() {
    local secret_file="$TARGET/storage/secrets/dns_servers.secret"
    [ ! -e "$secret_file" ] || die "secret ja existe apos extracao: $secret_file"
    openssl rand -hex 32 > "$secret_file"
    chmod 640 "$secret_file"
    log "Secret novo criado em $secret_file"
}

set_permissions() {
    log "Ajustando permissoes"
    chown -R root:root "$TARGET"
    chown -R www-data:www-data "$TARGET/storage"
    find "$TARGET/storage" -type d -exec chmod 750 {} +
    find "$TARGET/storage" -type f -exec chmod 640 {} +
    chmod 750 "$TARGET/storage"
}

write_apache_site() {
    log "Configurando Apache em $APACHE_SITE"
    cat > "$APACHE_SITE" <<EOF
<VirtualHost *:80>
    ServerName $SERVER_NAME
    DocumentRoot $TARGET/public

    <Directory $TARGET/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/painel-bind-error.log
    CustomLog \${APACHE_LOG_DIR}/painel-bind-access.log combined
</VirtualHost>
EOF
}

restore_apache_backup() {
    local backup
    backup="$(ls -1t "$APACHE_SITE".bak-* 2>/dev/null | head -n 1 || true)"
    if [ -n "$backup" ]; then
        cp -a "$backup" "$APACHE_SITE"
        log "Config Apache restaurada de $backup"
    else
        rm -f "$APACHE_SITE"
        log "Config Apache nova removida apos falha"
    fi
}

validate_and_reload_apache() {
    log "Validando Apache"
    if ! apachectl configtest >>"$LOG" 2>&1; then
        restore_apache_backup
        die "apachectl configtest falhou; Apache nao foi recarregado"
    fi

    if [ ! -e "$APACHE_ENABLED" ]; then
        a2ensite "$APACHE_SITE_NAME" >>"$LOG" 2>&1 || die "falha ao habilitar site Apache"
    fi

    if ! apachectl configtest >>"$LOG" 2>&1; then
        restore_apache_backup
        die "apachectl configtest falhou apos habilitar site; Apache nao foi recarregado"
    fi

    systemctl reload apache2
    log "Apache recarregado"
}

validate_http() {
    local code
    log "Validando HTTP local"
    for path in / /login.php; do
        code="$(curl -k -sS -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1$path" || true)"
        case "$code" in
            200|201|202|204|301|302|303|307|308)
                log "HTTP $path OK ($code)"
                ;;
            500|000)
                die "HTTP $path falhou ($code)"
                ;;
            *)
                log "HTTP $path retornou $code; revisar se esperado"
                ;;
        esac
    done
}

main() {
    log "Inicio da instalacao do Painel DNS Bind"
    log "Pacote: $PACKAGE"
    log "Alvo: $TARGET"
    log "ServerName: $SERVER_NAME"
    log "Firewall nftables exigido: $WITH_FIREWALL"

    validate_dependencies
    validate_package
    backup_apache_config
    confirm_empty_install_if_needed
    extract_release
    create_storage
    create_database
    create_admin_user
    create_secret
    set_permissions
    write_apache_site
    validate_and_reload_apache
    validate_http

    log "Instalacao concluida"
    log "Banco criado: $TARGET/storage/database/painel_dns.sqlite"
    log "Secret criado: $TARGET/storage/secrets/dns_servers.secret"
    log "Site Apache configurado: $APACHE_SITE"
    log "Bind NAO foi recarregado nem reiniciado automaticamente"

    cat <<EOF

Instalacao concluida.
Alvo: $TARGET
Banco: $TARGET/storage/database/painel_dns.sqlite
Secret: $TARGET/storage/secrets/dns_servers.secret
Apache: $APACHE_SITE
Log: $LOG

Bind NAO foi recarregado nem reiniciado automaticamente.
EOF
}

main "$@"
