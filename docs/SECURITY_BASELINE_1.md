# SECURITY-BASELINE-1

Esta fase endurece somente a superfície administrativa do DNS Center. Ela não
configura SMTP, não altera a arquitetura DNS, não implementa
primary/secondary, AXFR, IXFR, NOTIFY ou TSIG e não realiza deploy.

## Controles da aplicação

- Respostas web recebem CSP com nonce, `frame-ancestors 'none'`,
  `X-Frame-Options: DENY`, `nosniff`, Referrer Policy e Permissions Policy.
  Não são usados `unsafe-inline` nem `unsafe-eval`.
- HSTS é emitido somente em `production` quando a requisição é reconhecida
  como HTTPS.
- Em `local`, a CSP permite exclusivamente o Vite padrão em
  `localhost:5173` e `127.0.0.1:5173`. Essas exceções não existem em produção.
- JSON da API dos agentes não recebe CSP nem sessão web. A API mantém
  autenticação Bearer e rate limits próprios.
- O login mantém o limite por e-mail + IP e adiciona limite global por IP.
  Ambos usam mensagem genérica e janela temporária configurável.
- Falhas são gravadas como `auth.login_failed` com dados sanitizados e
  deduplicação. Senha, payload, cookie, sessão e headers completos não são
  gravados.
- Administradores da plataforma e da organização precisam de TOTP ou passkey.
  Sem fator, ficam em pendência e operações administrativas são restritas. O
  último fator não pode ser removido. Recuperação por CLI continua possível;
  SMTP não é pré-requisito.

## Proxy e IP real

`TRUSTED_PROXIES` fica vazio por padrão, então `X-Forwarded-For` enviado pelo
cliente é ignorado. Quando Nginx ou outro balanceador conecta diretamente ao
PHP, configure somente o IP/CIDR dessa camada:

```dotenv
TRUSTED_PROXIES=172.20.0.0/24
```

Não use `*`, não confie faixas da Internet e não liste endereços de clientes.
No Cloudflare, a borda/Nginx deve validar as faixas oficiais e sobrescrever o
header recebido antes de encaminhá-lo, mantendo essas faixas atualizadas. O IP
resolvido pelo Laravel alimenta rate limit e auditoria.

Recomenda-se futuramente hostname HTTPS separado para a API dos agentes, com
regras de rede e rate limit independentes do painel.

## Verificação antes de produção

Execute:

```bash
php artisan dns-center:security-check
```

O comando é somente leitura, não imprime segredos e termina com `OK`, `WARNING`
ou `BLOCKED`. Mailer/SMTP não participa da aprovação.

Antes de liberar produção:

- coloque o painel atrás de VPN ou allowlist de IP quando possível;
- use HTTPS obrigatório e `APP_DEBUG=false`;
- considere Cloudflare/WAF como camada opcional;
- não exponha PostgreSQL nem o Docker socket;
- configure firewall com política mínima;
- valide backups e testes periódicos de restauração;
- aplique atualizações de segurança do SO, imagens e dependências;
- rotacione credenciais e revogue acessos antigos;
- mantenha 2FA obrigatório para administradores.

O aplicativo não configura automaticamente VPN, WAF, firewall, PostgreSQL,
Docker socket ou backups nesta fase. Essas verificações dependem do ambiente de
produção e precisam de validação externa.
