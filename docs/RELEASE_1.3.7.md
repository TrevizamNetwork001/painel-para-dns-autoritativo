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
