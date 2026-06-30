# Handoff — Redesign da página de serviços

Data: 21/06/2026

## Objetivo

Aplicar em `services.php` o layout visual aprovado a partir da referência
`/home/cnetwork/00001.jpeg`, mantendo intacta a operação dos serviços, a
autenticação, o CSRF e a auditoria.

## Arquivo funcional alterado

- `/var/www/html/painel/services.php`

## Resultado entregue

- Tema escuro alinhado à referência visual.
- Barra lateral compacta com ícones SVG.
- Cabeçalho com título, descrição e botão de retorno ao painel.
- Alerta operacional destacado antes das ações.
- Cards de resumo para serviços monitorados, ativos, inativos e horário da
  última verificação.
- Grade responsiva com cards de BIND9, Fail2Ban, SSH e Firewall.
- Ícones SVG próprios para métricas, serviços, ações e navegação.
- Indicadores `Ativo` e `Inativo` com cores de estado.
- Botões de ação com largura, altura, espaçamento e estados de hover
  padronizados.
- Tabela de últimas ações com tipografia, espaçamento e cores alinhados à
  referência.
- Ícone azul antes do título `Últimas ações de serviços`.
- Status `OK` exibido com círculo verde contornado e check.
- Layout adaptado para desktop, tablet e celular.

## Aparência específica das ações

### BIND9

- `Verificar BIND`: ação neutra.
- `Recarregar BIND`: ação neutra.
- `Reiniciar BIND`: ícone verde, fundo escuro e hover verde.
- `Iniciar BIND`: ícone azul de play dentro de quadrado e hover azul.
- `Parar BIND`: estilo de perigo, ícone vermelho e brilho vermelho no hover.

### Fail2Ban

- `Verificar Fail2Ban`: ação neutra.
- `Reiniciar Fail2Ban`: ícone verde, fundo escuro e hover verde.

### SSH

- `Verificar SSH`: ação neutra.
- `Reiniciar SSH`: fundo vinho discreto, borda vermelha e ícone vermelho.

### Firewall

- `Verificar firewall`: ação neutra.
- `Recarregar firewall`: ação neutra.

## Segurança e lógica preservadas

Não foram alterados:

- comandos permitidos em `$acoes`;
- execução por `exec`;
- validação CSRF;
- autenticação central;
- registro em `audit_logs`;
- consulta das últimas ações;
- cálculo do estado dos serviços;
- links para dashboard e auditoria;
- arquivos de banco de dados;
- segredo de servidores DNS;
- regras do firewall.

As confirmações JavaScript foram mantidas ou ampliadas para:

- reiniciar BIND;
- parar BIND;
- reiniciar Fail2Ban;
- reiniciar SSH.

## Funções visuais relevantes

- `botoes_servico()`: define classes visuais por ação.
- `badge_status()`: renderiza o estado do serviço.
- `service_metric_icon()`: ícones dos cards de resumo.
- `service_action_icon()`: ícones dos botões.
- `service_icon()`: ícones dos serviços.
- `ui_icon_svg()`: catálogo local de SVGs, sem dependência externa.

## Commits da implementação

| Commit | Alteração |
|---|---|
| `877262b` | Aplicação inicial do layout da página de serviços |
| `b2c0090` | Padronização visual dos botões Iniciar/Reiniciar |
| `e8e3e3f` | Ícone no título das últimas ações |
| `97a91de` | Tipografia e espaçamento da tabela |
| `eda6570` | Aparência do status OK/ERRO na tabela |
| `fb26b47` | Destaque vermelho do ícone Parar BIND |
| `d19fbef` | Ícone verde para Reiniciar Fail2Ban |

## Validações executadas

```text
php -l /var/www/html/painel/services.php
No syntax errors detected in /var/www/html/painel/services.php
```

Também foi executado:

```text
git diff --check -- services.php
```

Sem erros de whitespace.

## Backup e referência

- Referência visual: `/home/cnetwork/00001.jpeg`
- Backup anterior à publicação inicial:
  `/home/cnetwork/services.php.pre-layout-20260621`

## Orientações para manutenção

1. Manter os estilos específicos por ação em classes `action-*`.
2. Não substituir os SVGs locais por bibliotecas externas sem necessidade.
3. Novas ações críticas devem receber confirmação explícita.
4. Não usar somente cor para indicar estado; manter texto e ícone.
5. Validar sempre com `php -l services.php` e `git diff --check`.
6. Em commits futuros, não incluir `db/*.sqlite`, segredos ou alterações de
   firewall sem que façam parte explícita do escopo.

## Estado final

A página está publicada no diretório ativo e os commits da implementação estão
na branch `master`.
