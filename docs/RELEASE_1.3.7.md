# DNS Center v1.3.7

Data: 2026-09-16

## Escopo

Três correções pequenas, todas sem alteração de schema:

1. **CI**: pin de Python 3.13 no workflow (`04350c6`) — não resolveu sozinho
   a causa raiz, mas fica como boa prática de reprodutibilidade.
2. **Teste do agente**: `test_configuration_failure_restores_named_conf`
   não mockava `os.geteuid()`, então só passava quando executado como
   root (o caso local, mas não o do runner do GitHub Actions). Sem o
   mock, `agent.configure_bind()` levantava "Operação BIND autorizada
   exige root" antes de sequer exercitar o rollback que o teste queria
   validar, mascarando o próprio comportamento sob teste. Corrigido
   (`068b74d`) e confirmado rodando a suíte inteira como usuário
   não-root (`su nobody`) antes de publicar.
3. **CSS da aba Nameservers**: vários elementos de texto (título de
   identidade/perfil, contador "N NS", itens da lista de um perfil) não
   tinham `font-size` definido — único ponto do painel com essa lacuna,
   já que não existe reset global de `h1`/`h2`/`h3`. O navegador caía no
   tamanho padrão dele (bem maior), destoando do resto do painel
   (Servidores, Domínios), que define tamanho explícito em tudo.
   Alinhado à escala já usada em `.server-row-main` e
   `.domain-section-header h2` (`b8088bb`).

Também nesta janela: histórico completo do repositório foi publicado
pela primeira vez em
[`github.com/TrevizamNetwork001/painel-para-dns-autoritativo`](https://github.com/TrevizamNetwork001/painel-para-dns-autoritativo)
(antes só existia um commit inicial isolado lá), com CI do GitHub
Actions habilitado e verde.

## Testes e gates

- `php artisan test`: 244 testes, 1330 assertions — sem regressão.
- `python3 -m unittest discover -s tests/Agent`: 104 testes, confirmado
  como root e como usuário não-root.
- Build do frontend (`vite build`): sem erro.
- CI do GitHub Actions (commit `068b74d9`): verde.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `ea6b5b8` (tag
`v1.3.7`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.7
```

- imagens `dns-center-app:1.3.7`/`dns-center-web:1.3.7` construídas
  localmente e conferidas via `composer audit` ("No security
  vulnerability advisories found.") antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T133619Z.dump`, 2326543 bytes, SHA-256
  `2d052a7e03567a675d0a446d9e66809ff2ff967d068bb83965936375174beffa`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.7`, `previous-version=1.3.6` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação (`app`, `web`, `queue`, `scheduler`) confirmados
na imagem `1.3.7` e saudáveis.
