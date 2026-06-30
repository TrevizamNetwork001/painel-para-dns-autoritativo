# Handoff FIREWALL_V1.2

Data: 2026-06-21

Escopo concluído:

- Separação visual e lógica das ACLs em IPv4 e IPv6.
- CRUD operacional de ACL IPv4.
- CRUD operacional de ACL IPv6.
- CRUD operacional de portas administrativas.
- CRUD operacional de portas públicas.
- Auditoria real para sucesso e erro.
- Mensagens de flash em toast com auto-hide.

Arquivo alterado:

- `firewall.php`

O que foi implementado:

- Validação de família de ACL no cadastro e na edição.
- Normalização de IPv4, IPv6 e redes CIDR.
- Bloqueio de duplicidade para ACL e portas.
- Edição e remoção com confirmação explícita.
- Auditoria com tipo de registro por família/escopo.
- Cards e blocos separados para:
  - ACL IPv4
  - ACL IPv6
  - Portas administrativas
  - Portas públicas
- Toast de status para feedback de operação.

O que foi validado:

- `php -l firewall.php`
- Cadastro de IPv4 válido
- Cadastro de IPv6 válido
- Edição de porta
- Remoção de porta com confirmação exata
- Rejeição de entrada inválida por helper
- Rejeição de CSRF inválido
- Toast com auto-hide na mensagem de sucesso

Fora de escopo desta fase:

- `nft`
- `nft -c`
- `systemctl`
- reload real do firewall
- rollback
- aplicação de regras produtivas

Próximas fases sugeridas:

- V1.5: validação controlada antes de aplicar
- V1.6: aplicação real com rollback e reload controlado
