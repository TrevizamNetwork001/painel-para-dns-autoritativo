# DNS Center v1.3.22

Data: 2026-09-16

## Escopo

Template de nome pro PTR gerado a partir de registros A — segunda
ideia trazida do projeto antigo (PHP puro) pelo operador. Lá, ao
criar um domínio, dava pra escolher o formato do nome usado nos PTR
gerados (`host-$`, customizado, etc). Confirmado com o operador: essa
ideia entra só pros PTR gerados **a partir de registros A já
cadastrados no painel** — o sistema antigo gerava PTR pra todo IP do
bloco reverso mesmo sem A correspondente; isso fica fora de escopo.

### Mudança

- Coluna nova `dns_zones.ptr_name_template` (nullable, opcional).
  Vazio/nulo mantém o comportamento de hoje (PTR usa o nome exato do
  registro A correspondente).
- `DnsReversePtrSynchronizer::synchronizeFromForwardZone()` ganha um
  terceiro parâmetro opcional `$nameTemplate`. Quando presente,
  substitui `$` pela parte de host do IP (os octetos fora da rede da
  zona reversa, unidos por `-` — ex. `10` num bloco `/24`, `5-7` num
  bloco `/16`) e usa isso como nome do PTR em vez do nome do A.
- Campo "Modelo de nome do PTR" (opcional, só IPv4) disponível em dois
  lugares: no modal "Criar zona reversa" e na aba "Configuração" de
  uma zona reversa já existente (editável a qualquer momento). A aba
  "Publicação" mostra qual modelo está em uso (ou avisa que nenhum
  está configurado) ao lado do botão "Gerar PTR a partir dos
  registros A".
- Validação: exige exatamente um `$` no template, só caracteres de
  label DNS (`[a-z0-9_-]`), até 50 caracteres.

## Testes e gates

- 6 testes novos em `tests/Feature/DnsReverseZoneWizardTest.php`:
  template salvo em zona IPv4, ignorado em zona IPv6, template
  inválido rejeitado, PTR gerado usa o template configurado, template
  junta corretamente múltiplos octetos de host num bloco `/16`,
  edição do template pela aba Configuração.
- `php artisan test`: 296 testes, 1476 assertions — sem regressão
  (290 → 296).
- Pint: 176 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Pendente de execução pelo operador. Esta versão inclui uma migration
nova (`migrate --force` roda como parte do `dns-center-deploy
update`).
