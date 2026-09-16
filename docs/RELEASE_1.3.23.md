# DNS Center v1.3.23

Data: 2026-09-16

## Escopo

Fix de UX pequeno: os comandos de instalação/enrollment/atualização
do agente exibidos na tela do servidor sempre prefixavam `sudo`, mas
nem todo servidor tem `sudo` instalado — encontrado ao vivo tentando
rodar o comando de enrollment no ns2 do cliente-exemplo, logado direto como
root (`sudo: comando não encontrado`).

### Mudança

`resources/views/servers/agent.blade.php`: os três comandos exibidos
("Agente já instalado", fallback de upgrade, "Máquina sem agente")
passam a usar `$(command -v sudo || true)` no lugar de `sudo` fixo —
se `sudo` existir no PATH, o comando roda com ele; se não existir
(comum em imagens mínimas com login direto como root), o prefixo some
sozinho e o comando roda sem erro, sem precisar editar nada na hora.

## Testes e gates

- `php artisan test`: 296 testes, 1476 assertions — sem regressão
  (nenhuma mudança de lógica, só texto exibido).
- Blade compila sem erros (`view:cache`).

## Deploy

Pendente de execução pelo operador.
