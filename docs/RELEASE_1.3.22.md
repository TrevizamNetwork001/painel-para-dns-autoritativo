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

Executado em produção em 2026-09-16, a partir do HEAD `2d1f458` (tag
`v1.3.22`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.22
```

- imagens `dns-center-app:1.3.22`/`dns-center-web:1.3.22` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado antes da migration:
  `dns-center-20260916T204834Z.dump`, 2515108 bytes, SHA-256
  `244cf084ec191f575370d0d2c3c5594765dc2a378c53a24013c00645d2adad82`;
- `migrate --force`: `2026_09_16_210000_add_ptr_name_template_to_dns_zones`
  aplicada com sucesso (`migrate:status` confirmado como `Ran`);
- corte de tráfego bem-sucedido.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.22`.
