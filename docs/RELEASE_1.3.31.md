# Release 1.3.31

## Desativar e excluir empresa (tenant)

Necessário para o cancelamento de um cliente: até aqui não existia forma de revogar
os acessos nem de remover os dados de uma empresa.

### Desativar / reativar (reversível)
- `PATCH /empresas/{organization}/status` (`organizations.status`), só administrador
  da plataforma.
- Empresa `inactive`: `EnsureOrganizationContext` responde 403 ("Esta empresa está
  desativada.") a qualquer usuário que não seja administrador da plataforma.
- Auditoria `organization.status_updated`.

### Excluir definitivamente (irreversível)
- `DELETE /empresas/{organization}` (`organizations.destroy`), só administrador da
  plataforma, com **confirmação forte**: é preciso digitar o nome exato da empresa
  (modal na tela de Empresas); diferente disso, 409.
- Cascata pelo banco (FK `CASCADE`): servidores, agentes (credenciais revogadas junto),
  zonas, registros, operações BIND, descobertas, perfis/identidades de nameserver etc.
  Nada é enviado aos servidores BIND.
- Usuários que pertencem **só** àquela empresa são apagados; quem pertence a outra
  empresa também é preservado.
- **Trilha de auditoria preservada de propósito**: `dns_audit_logs`, `security_audits`
  e `users.current_organization_id` usam `SET NULL`, não `CASCADE`.
  A auditoria `organization.deleted` é gravada **antes** de excluir.
- A empresa padrão da plataforma (`is_default`) não pode ser desativada nem excluída
  (409).
- As duas rotas entram na lista de operações críticas do 2FA
  (`EnsureAdminTwoFactor::isCriticalOperation`).
- 9 testes novos em `OrganizationManagementTest`.

### Caso real (18/09/2026)
Cancelamento do Cliente legado: empresa desativada e depois excluída; verificado por
consulta direta que a cascata foi completa. Restos de uma homologação antiga (servidores
`ns1`/`ns2` do legacy-customer, 6 zonas em rascunho, 2075 registros, descobertas e
operações) estavam na empresa padrão e foram removidos à parte. As 20 entradas de
log de auditoria que citam o nome foram **mantidas por decisão do operador**.
