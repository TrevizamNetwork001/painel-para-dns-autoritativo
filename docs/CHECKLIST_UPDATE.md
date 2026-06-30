# Checklist de Atualizacao Futura

- [ ] Backup `storage/database`.
- [ ] Backup `storage/secrets`.
- [ ] Backup Apache site config.
- [ ] `git status` limpo.
- [ ] Release gerado via `git archive`.
- [ ] Release validado.
- [ ] Aplicacao testada em staging ou diretorio temporario.
- [ ] Migrations revisadas.
- [ ] Permissoes revisadas.
- [ ] `apachectl configtest`.
- [ ] Reload Apache somente se `configtest` passar.
- [ ] Validacao HTTP.
- [ ] Plano de rollback pronto.
- [ ] Bind reload somente manual e explicito, se necessario.
