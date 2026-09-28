# Pendências técnicas conhecidas

Este documento registra comportamentos confirmados que ainda precisam de
correção no produto. Cada item deve permanecer aqui até existir implementação,
teste de regressão e release publicada; a partir daí, o item sai deste
documento e a release correspondente (`docs/RELEASE_X.Y.Z.md`) passa a ser o
registro histórico.

Nenhuma pendência aberta no momento.

## Histórico

- **DISC-001** — falso conflito por nomes DNS equivalentes com e sem ponto
  final (descoberta de zonas BIND). Detectado em 20/09/2026, painel 1.3.51.
  Corrigido na release 1.3.52 — ver `docs/RELEASE_1.3.52.md`.
