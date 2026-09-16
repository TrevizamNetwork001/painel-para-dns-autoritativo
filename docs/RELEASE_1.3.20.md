# DNS Center v1.3.20

Data: 2026-09-16

## Escopo

Detalhamento das entradas da aba "Histórico" (versões do domínio),
pedido pelo operador ao ver que a v9 de uma zona só dizia "Registro
atualizado." sem nenhuma informação sobre qual registro ou o que
mudou.

Não existe um sistema de auditoria separado pra mudanças de zona/
registro no projeto — o `SecurityAudit` existente é só pra eventos de
segurança (login, 2FA). A aba "Histórico" (`DnsZoneVersion.reason`) já
é o registro de mudanças de zona; o problema era só o texto genérico.

### Mudança

`app/Http/Controllers/DnsZoneController.php` — as mensagens de razão
gravadas em `DnsZoneVersion` para operações de registro agora incluem
o registro afetado:

- `storeRecord()`: `Registro adicionado: www A → 192.0.2.40.`
- `updateRecord()`: `Registro atualizado: web AAAA → 2001:db8::40
  (era www A → 192.0.2.40).` — quando nome/tipo/conteúdo não mudam,
  simplifica pra `Registro atualizado: web AAAA → 2001:db8::40.`
  (sem repetir o "antes" quando é igual ao "depois").
- `destroyRecord()`: `Registro removido: web AAAA → 2001:db8::40.`

As mensagens de zona (`Zona publicada.`, `Parâmetros da zona
atualizados.`, etc.) não foram alteradas nesta versão.

## Testes e gates

- `tests/Feature/DnsZoneWorkflowTest.php::test_record_create_update_and_delete_remain_pending`
  estendido com asserções sobre o texto exato gravado em
  `dns_zone_versions.reason` pras três operações (adicionar, editar,
  remover).
- `php artisan test`: 281 testes, 1416 assertions — sem regressão.
- Pint: 170 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Pendente de execução pelo operador.
