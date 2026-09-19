# DNS Center v1.3.9

Data: 2026-09-16

## Escopo

Assistente de DNS reverso IPv4/IPv6, substituindo o placeholder
estático que existia na aba "DNS reverso" da zona
("Nenhum reverso associado... Funcionalidade preparada para a
próxima fase"). Pedido do operador ao notar o placeholder na zona
`legacy.example`.

### O que o assistente faz

- Botão "Criar zona reversa" na aba "DNS reverso" abre um modal (mesmo
  padrão visual do modal de criar domínio): informa a família (IPv4/
  IPv6), o bloco CIDR exato (endereço de rede, não um IP qualquer
  dentro dele), o perfil de nameservers e os servidores de publicação
  — os mesmos dados já pedidos ao criar uma zona normal.
- **IPv4**: só blocos alinhados em octeto (`/8`, `/16`, `/24`) no v1 —
  RFC 2317 (delegação classless `/25`-`/31`) fica pra depois.
- **IPv6**: prefixo alinhado a nibble (múltiplo de 4 bits), formato
  já visto nos dados reais de cliente legado (`2001:db8::/32` →
  `8.b.d.0.1.0.0.2.ip6.arpa`).
- A zona reversa é criada pelo mesmo caminho de criação de zona normal
  (`DnsZoneController::createZone()`, extraído de `store()` pra ser
  reaproveitado) — NS automático do apex, sem alteração de
  comportamento aí.
- **Geração de PTR continua sendo um passo separado e deliberado**,
  igual o botão "Vincular PTR das identidades de nameserver" que já
  existia: na aba Publicação de uma zona reversa IPv4, um novo form
  deixa escolher um domínio direto da organização e gera/atualiza os
  registros PTR a partir dos registros A desse domínio que caem dentro
  do bloco da zona reversa (`DnsReversePtrSynchronizer::synchronizeFromForwardZone()`).
- **PTR de IPv6 continua 100% manual** — restrição já estabelecida
  nesta sessão. O assistente cria a zona `ip6.arpa` vazia (só NS); os
  registros PTR dela são cadastrados à mão pela tela de registros que
  já existe, sem nenhuma automação nova.
- A aba "DNS reverso" agora lista as zonas `in-addr.arpa`/`ip6.arpa`
  já existentes na mesma organização (sem vínculo de schema novo —
  continua sendo por `organization_id`, igual o app já "associa"
  essas zonas hoje implicitamente).

### Novo código

- `app/Services/ReverseZoneNameCalculator.php`: calcula o nome da zona
  reversa a partir de um bloco CIDR IPv4 ou prefixo IPv6.
- `app/Models/DnsZone.php`: novo `isIpv6ReverseZone()` — método
  separado do `isReverseZone()` existente (que continua só IPv4), pra
  não fazer o botão de PTR automático aparecer em zona IPv6.
- `app/Services/DnsReversePtrSynchronizer.php`: novo
  `synchronizeFromForwardZone()`, reaproveitando `recordNameFor()` já
  existente, agora escaneando registros A de uma zona direta em vez de
  identidades de nameserver.
- `app/Http/Controllers/DnsZoneController.php`: `createZone()`
  extraído de `store()` (mesma lógica, sem mudança de comportamento),
  mais `storeReverse()` e `generatePtrFromForwardZone()`.
- Rotas novas: `POST /zonas/reversa` (`zones.reverse.store`),
  `POST /zonas/{zone}/gerar-ptr-de-zona` (`zones.reverse.generate-ptr`).

## Testes e gates

- `tests/Unit/ReverseZoneNameCalculatorTest.php`: 9 testes — blocos
  IPv4 `/24`/`/16`/`/8` válidos, rejeição de prefixo não
  octeto-alinhado, rejeição de endereço que não é o de rede, rejeição
  de CIDR sem prefixo; IPv6 `/32` batendo com o dado real de cliente legado,
  rejeição de prefixo não múltiplo de 4, rejeição de endereço que não
  é o de rede.
- `tests/Feature/DnsReverseZoneWizardTest.php`: 7 testes — criação de
  zona IPv4 e IPv6 com NS automático, rejeição de bloco não
  alinhado, rejeição de zona reversa duplicada, 403 pra
  operator/viewer, geração de PTR só pros endereços A dentro do bloco
  (endereço fora não gera PTR), 404 ao tentar gerar PTR numa zona que
  não é reversa.
- `php artisan test`: 260 testes, 1366 assertions — sem regressão.
- Pint: 169 arquivos aprovados.
- `route:list`: rotas novas confirmadas.
- Build do frontend (`vite build`): sem erro.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `f5f31eb` (tag
`v1.3.9`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.9
```

- primeira tentativa de build falhou por instabilidade transitória de
  rede (timeout no `apt-get`/`pecl install redis` durante a instalação
  de dependências PHP da imagem) — resolvida na segunda tentativa, sem
  mudança de código;
- imagens `dns-center-app:1.3.9`/`dns-center-web:1.3.9` construídas
  localmente, suíte completa (260 testes) reconferida na imagem final
  antes do corte de tráfego, e `composer audit` sem vulnerabilidades;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T150638Z.dump`, 2333294 bytes, SHA-256
  `47f598c11888bf5d319df9ef61ac46518fc21a0b809d82be23d47393cc41acb4`;
- `migrate:status`/`migrate --force`: nenhuma migration nova (sem
  alteração de schema);
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.9`, `previous-version=1.3.8` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.9` e saudáveis.
