# Checklist de Instalacao Nova

- [ ] Git limpo.
- [ ] Backups removidos do Git.
- [ ] `.gitignore` reforcado.
- [ ] Release gerado via `git archive`.
- [ ] Release validado sem SQLite, secret, env, key, log ou backup.
- [ ] Dependencias OK.
- [ ] `storage` criado.
- [ ] Banco novo criado via `db/schema.sql`.
- [ ] Admin inicial criado.
- [ ] Secret novo gerado.
- [ ] Permissoes aplicadas.
- [ ] Apache `DocumentRoot` aponta para `public/`.
- [ ] `apachectl configtest` OK.
- [ ] Apache reload OK.
- [ ] HTTP `/` OK.
- [ ] HTTP `/login.php` OK.
- [ ] Bind nao foi reiniciado/recarregado automaticamente.
