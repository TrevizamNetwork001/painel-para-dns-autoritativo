# Proposta futura: firewall via agent — 19/09/2026

## Contexto

No projeto inicial, o painel PHP era instalado localmente em cada servidor do
cliente. A página de firewall podia executar `nft` e `sudo` diretamente porque
interface e firewall estavam na mesma máquina.

O DNS Center atual possui outra arquitetura: o painel é central e cada
servidor autoritativo do cliente executa um agent local. Portanto, copiar a
página antiga para o painel atual faria o processo web tentar administrar
máquinas remotas e reintroduziria SSH, credenciais administrativas ou execução
remota genérica. Esse modelo foi rejeitado.

## Distribuição correta das responsabilidades

```text
Painel central
  └─ interface, configuração desejada, 2FA, autorização e auditoria
       ↓ HTTPS iniciado pelo agent / operação autorizada
Agent no servidor do cliente
  └─ inventário, validação, aplicação isolada e rollback do nftables
```

### Painel central

A funcionalidade deverá aparecer no contexto de um servidor específico:

`Servidores → servidor selecionado → Firewall`

O painel será responsável por:

- cadastrar ACLs administrativas IPv4 e IPv6;
- separar portas públicas de portas administrativas;
- manter a configuração desejada por organização e por servidor;
- gerar e exibir uma prévia legível;
- comparar estado desejado e estado observado;
- exigir perfil administrativo, 2FA e confirmação forte;
- criar uma operação fechada para o agent;
- registrar autoria, horários, alterações e resultados sanitizados;
- exibir validação, aplicação, falha e rollback.

O painel não deverá armazenar senha ou chave SSH, executar `nft`, abrir conexão
administrativa com o cliente nem aceitar comandos livres.

### Agent no servidor do cliente

O agent será o único componente autorizado a interagir com o firewall local.
Ele deverá:

- coletar somente o inventário necessário da tabela gerenciada;
- aceitar um contrato versionado e com campos allowlisted;
- normalizar e validar endereços, prefixos, portas e protocolos;
- gerar uma tabela exclusiva do DNS Center;
- executar `nft -c` antes de qualquer mudança;
- preservar uma cópia válida do estado anterior;
- aplicar a mudança de forma transacional;
- verificar conectividade e invariantes depois da aplicação;
- executar rollback automático quando a confirmação falhar;
- devolver ao painel apenas status, hashes, contagens e erros sanitizados.

## Limite de propriedade

O módulo não será um administrador geral do firewall. O agent deverá controlar
somente uma tabela isolada, por exemplo `inet dns_center`. Ele não poderá:

- substituir ou limpar o ruleset completo;
- alterar tabelas do Docker, provedor, sistema ou operador;
- editar regras externas que não tenham sido criadas pelo DNS Center;
- modificar política de saída;
- criar encaminhamento de tráfego;
- executar comandos enviados pelo painel;
- inferir que uma regra desconhecida pode ser removida.

DNS TCP/UDP 53 e a comunicação HTTPS de saída do agent precisam permanecer
protegidos por invariantes explícitos. Acesso administrativo só poderá ser
alterado com pelo menos uma ACL válida; prefixos IPv4 ou IPv6 abertos em `/0`
deverão ser recusados nesse contexto.

## Elementos aproveitáveis do projeto inicial

A implementação antiga poderá servir apenas como especificação funcional para:

- separação entre ACLs, portas administrativas e portas públicas;
- prévia de regras;
- validação anterior à aplicação;
- confirmação forte;
- backup e rollback;
- histórico e auditoria;
- resumo de contagens e avisos.

Não serão copiados os controllers PHP, tabelas SQLite, chamadas `proc_open`,
integração com `sudo`, scripts antigos, credenciais ou dados de ambiente.

## Implantação recomendada

### Fase 1 — somente leitura

- detectar se `nftables` está disponível;
- reportar presença da tabela exclusiva;
- reportar hash, contagens e última validação;
- não alterar qualquer regra.

### Fase 2 — configuração e prévia

- cadastrar ACLs e portas no painel;
- gerar contrato e prévia determinísticos;
- validar localmente com `nft -c`;
- devolver o resultado sem aplicar.

### Fase 3 — homologação descartável

- testar aplicação e rollback em VM ou container privilegiado dedicado;
- simular regra inválida, timeout, perda de conectividade e processo abortado;
- confirmar que regras externas não mudam;
- cobrir concorrência e idempotência.

### Fase 4 — piloto controlado

- habilitar aplicação em um servidor não crítico;
- exigir 2FA e frase de confirmação;
- manter rollback automático e evidência de auditoria;
- observar vários ciclos do agent antes de ampliar o rollout.

## Decisão atual

A funcionalidade é útil, mas está adiada. Nenhum código de firewall do projeto
antigo será incorporado agora. Quando retomada, a interface ficará no painel
central e toda leitura ou alteração real será executada localmente pelo agent,
sem SSH e dentro do limite de propriedade descrito neste documento.
