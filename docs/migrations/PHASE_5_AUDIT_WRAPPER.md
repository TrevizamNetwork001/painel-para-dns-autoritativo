# Fase 5 - Wrapper de auditoria em app

## Objetivo

Criar um ponto de entrada compativel em `app/Audit/audit.php` para preparar a migracao futura do modulo de auditoria, sem substituir o runtime atual.

## Arquivo criado

- `app/Audit/audit.php`

O wrapper carrega:

1. `app/Support/bootstrap.php`
2. `includes/audit.php`

Ambos sao carregados com `require_once`.

## Fonte da verdade atual

`includes/audit.php` continua sendo a fonte da verdade para auditoria.

As funcoes atuais seguem definidas somente no include legado:

- `audit_usuario_atual()`
- `audit_ip_atual()`
- `audit_dominio_base()`
- `audit_ptr_ipv6_para_endereco()`
- `audit_valor_ptr()`
- `registrar_auditoria()`

O wrapper nao redefine nenhuma dessas funcoes.

## O que nao mudou

- Nenhuma pagina publica foi alterada.
- Nenhum include antigo foi movido.
- Nenhuma logica DNS foi alterada.
- Nenhum comando operacional e executado pelo wrapper.
- Nenhum reload/restart do Bind foi executado.
- Nenhum banco ou secret foi alterado.

## Como isso prepara a migracao

Fases futuras podem apontar codigo novo para `app/Audit/audit.php` enquanto o codigo legado continua usando `includes/audit.php`.

Quando a migracao real ocorrer, o wrapper podera virar fachada para classes/funcoes em `app/Audit/`, mantendo compatibilidade com os nomes atuais ate que as paginas publicas sejam migradas com seguranca.

## Cuidados para proximas fases

- Nao mover `includes/audit.php` ainda.
- Nao alterar schema de `audit_logs` nesta etapa.
- Nao trocar chamadas existentes de `registrar_auditoria()` sem testes.
- Validar login, logout, usuarios, dominios, zonas, DNS servers e firewall antes de qualquer substituicao de include.
