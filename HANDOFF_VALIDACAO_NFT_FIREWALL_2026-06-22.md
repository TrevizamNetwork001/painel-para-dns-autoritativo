# Handoff — Validação nftables do Firewall

Data: 2026-06-22

## Problema

A última validação falhava com `sudo: a password is required` porque o Apache
não possuía permissão para executar a checagem nftables.

## Solução

- Wrapper versionado em `scripts/painel-firewall-validar`.
- Cópia operacional em `/usr/local/sbin/painel-firewall-validar`, pertencente
  a root e não gravável pelo Apache.
- Regra sudoers específica:

  `www-data ALL=(root) NOPASSWD: /usr/local/sbin/painel-firewall-validar *`

- O PHP chama esse wrapper apenas em `firewall_executar_validacao()`.

## Restrições do wrapper

- Aceita exatamente um arquivo `/tmp/fw-preview-*`.
- Exige arquivo regular, sem link simbólico, proprietário UID 33 e modo 0600.
- Limita o arquivo a 1 MiB.
- Bloqueia diretivas `include` e `import`.
- Copia o conteúdo para arquivo root-owned antes de executar `nft -c -f`.

## Escopo

- A validação apenas verifica a sintaxe.
- Nenhuma regra é aplicada.
- Aplicação e rollback não receberam nova permissão.

## Validação

- `php -l firewall.php`
- `sh -n scripts/painel-firewall-validar`
- `visudo -cf /etc/sudoers.d/painel-firewall-validar`
- Teste como `www-data` usando `sudo -n`.
