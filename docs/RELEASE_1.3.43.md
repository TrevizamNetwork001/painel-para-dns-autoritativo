# Release 1.3.43

## Configurações → Backup do banco (credenciais do R2 pelo painel)

- **Nova área Configurações** (só administrador da plataforma; rotas `settings.backup.*`,
  protegidas por 2FA como operação crítica). Link "Configurações" na barra lateral.
- Tela **Backup do banco**: Account ID, bucket, pasta, ID da chave de acesso e chave secreta
  do Cloudflare R2. Validação restringe os caracteres (`[A-Za-z0-9._/-]`), pois os valores são
  lidos por um script do servidor. A chave secreta nunca é exibida; em branco ao editar mantém
  a atual.
- **Criptografadas no banco** com a `APP_KEY` (`platform_settings`, cast `encrypted:array`):
  nem o banco nem os dumps de backup contêm as chaves em texto puro.
- **Testar conexão**: o painel assina (AWS SigV4, `App\Support\R2Client`) e envia, confere e
  apaga um objeto minúsculo no bucket; mensagens de erro claras (credencial recusada, bucket
  inexistente) sem vazar segredos. **Remover credenciais** volta ao backup só local.
- `php artisan dns-center:backup-r2-config` imprime `R2_*=valor` (falha silenciosa se não
  configurado); `dns-center-deploy backup` usa as credenciais do painel e, sem elas, o arquivo
  `DNS_CENTER_BACKUP_REMOTE_ENV`. Endpoint sobrescrevível por `DNS_CENTER_BACKUP_R2_ENDPOINT`
  (usado nos testes).
- **A senha de criptografia dos dumps continua em arquivo no servidor**, de propósito (no
  banco, não abriria após um desastre).
- Migration `platform_settings`. Auditoria: `backup.settings_updated`,
  `backup.connection_tested`, `backup.connection_test_failed`, `backup.settings_removed`.
- Testes: 13 (PHP) — inclusive a assinatura SigV4 contra o exemplo documentado da AWS — e 3
  (Python) que executam o `upload_backup_remote` real contra um R2 falso que verifica a
  assinatura de forma independente, a criptografia ida-e-volta e a falha com chave errada.
