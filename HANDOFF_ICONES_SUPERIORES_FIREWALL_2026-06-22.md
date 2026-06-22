# Handoff — Ícones superiores do Firewall

Data: 2026-06-22

## Objetivo

Reproduzir com maior fidelidade os ícones dos cinco cards abaixo do texto
`Controle de acesso e portas públicas`, usando `esse.png` como referência.

## Alterações

- IPv4 Liberados: escudo azul com proteção interna.
- IPv6 Liberados: escudo verde com folha interna.
- Portas Admin: cadeado laranja.
- Portas Públicas: globo roxo com meridianos e paralelos.
- Firewall: escudo verde com detalhe de conexão.
- SVGs redesenhados em `viewBox` 32 × 32.
- Contêineres ampliados para 40 × 40 com preenchimento translúcido.

## Escopo preservado

- Contagens e estados permanecem inalterados.
- Nenhuma regra de firewall foi modificada ou aplicada.
- Alteração exclusivamente visual.

## Validação

- `php -l firewall.php`
- `git diff --check`
