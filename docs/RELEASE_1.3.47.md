# Release 1.3.47 — exclusão integral de tenant

Ao excluir uma empresa, o banco removia servidores, zonas, agentes e demais
dados operacionais em cascata, mas preservava auditorias com
`organization_id = null`. Isso deixava textos históricos associados ao tenant.

Agora a exclusão:

- apaga as auditorias DNS e de segurança da empresa dentro da mesma transação;
- não cria um novo evento com o nome da empresa excluída;
- usa `ON DELETE CASCADE` nas duas chaves estrangeiras de auditoria como
  proteção adicional;
- preserva usuários que também pertencem a outra empresa e dados de outros
  tenants.

Validação: 13 testes direcionados, 47 verificações e Pint nos três arquivos
alterados.
