# Fase 5.3 - Wrapper de zonas DNS em app

## Objetivo

Criar um wrapper compativel em `app/Dns/zones.php` para preparar a migracao futura do modulo de zonas DNS, sem substituir o runtime atual.

## Arquivo criado

- `app/Dns/zones.php`

O wrapper carrega:

1. `app/Support/bootstrap.php`
2. `includes/dns_zones.php`

Ambos sao carregados com `require_once`.

## Fonte da verdade atual

`includes/dns_zones.php` continua sendo a fonte da verdade para inventario, comparacao, governanca e sincronizacao de zonas DNS.

O wrapper nao redefine funcoes existentes e nao executa comandos por conta propria. Qualquer efeito operacional continua vindo exclusivamente das chamadas atuais feitas pelas paginas existentes.

## O que nao mudou

- Nenhuma pagina publica foi alterada.
- Nenhum include existente foi alterado.
- Nenhuma logica DNS foi alterada.
- Nenhum reload/restart do Bind foi executado.
- Nenhum banco real foi movido ou alterado.
- Nenhum secret foi lido, movido ou alterado.

## Como isso prepara a migracao

Codigo novo podera depender de `app/Dns/zones.php` enquanto paginas e includes antigos continuam usando `includes/dns_zones.php`.

Em fases futuras, esse wrapper pode virar fachada para uma camada em `app/Dns/`, mantendo compatibilidade com as funcoes atuais ate que as paginas publicas sejam migradas e testadas.

## Cuidados para proximas fases

- Nao substituir `includes/dns_zones.php` nas paginas publicas sem testes de inventario.
- Separar primeiro funcoes puras de parsing/comparacao.
- Migrar sincronizacao e comandos remotos apenas depois de isolar efeitos colaterais.
- Validar que nenhuma migracao chama `rndc`, `named-checkconf`, SSH ou sudo fora de fluxo explicitamente autorizado.
- Manter remocao automatica de zonas bloqueada por padrao.
