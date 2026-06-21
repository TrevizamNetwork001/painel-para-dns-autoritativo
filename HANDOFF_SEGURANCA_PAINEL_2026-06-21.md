# Handoff — Hardening futuro do painel

Data: 21/06/2026

## Objetivo

Registrar opções de proteção para o Painel DNS/Firewall antes de qualquer
implantação. Este documento é apenas planejamento: nenhuma configuração,
regra, serviço ou código de segurança foi alterado por este handoff.

## Contexto de risco

As portas HTTP/HTTPS expostas à Internet serão encontradas e testadas
automaticamente por scanners, bots e botnets.

A porta 443 e o uso de HTTPS protegem o tráfego em trânsito, mas não corrigem
vulnerabilidades no PHP, no servidor web, na autenticação ou nas permissões do
sistema.

Como o painel administra DNS, serviços e futuramente o firewall, uma falha na
aplicação pode ter impacto superior ao de um site institucional comum.

## Arquitetura recomendada

```text
Internet
  └── HTTPS 443 para serviços realmente públicos

VPN ou IP administrativo autorizado
  └── Painel administrativo
        ├── MFA
        ├── sessão protegida
        ├── reautenticação para ações críticas
        ├── privilégio mínimo
        └── auditoria e alertas
```

O painel administrativo não deve ficar livremente acessível pela Internet
quando for possível restringi-lo por VPN ou lista de IPs.

## Prioridade 1 — Reduzir exposição

### VPN administrativa

- Avaliar WireGuard para acesso ao painel.
- Publicar o painel apenas no endereço da VPN.
- Manter um procedimento controlado de recuperação caso a VPN fique
  indisponível.

### Lista de IPs

- Como alternativa ou camada adicional, permitir acesso ao painel somente a
  redes administrativas conhecidas.
- Não depender exclusivamente dessa medida quando operadores utilizarem IPs
  dinâmicos.
- Manter acesso de emergência documentado e auditado.

### Separação de serviços

- Usar virtual host ou subdomínio exclusivo para o painel.
- Separar o site público do painel administrativo.
- Não expor diretórios, arquivos de banco, backups, segredos ou arquivos de
  configuração pelo servidor web.

## Prioridade 2 — Transporte e servidor web

### HTTPS

- Manter todas as páginas autenticadas exclusivamente em HTTPS.
- Usar a porta 80 apenas para redirecionamento permanente para HTTPS, quando
  ela precisar permanecer pública.
- Revisar protocolos TLS, cifras e renovação dos certificados.
- Avaliar HSTS depois de confirmar que todo o ambiente funciona corretamente
  em HTTPS.

### Cabeçalhos HTTP

Avaliar e testar:

- `Content-Security-Policy`;
- `Strict-Transport-Security`;
- `X-Content-Type-Options`;
- proteção contra carregamento em frames;
- `Referrer-Policy`;
- `Permissions-Policy`.

Esses cabeçalhos devem ser implantados gradualmente para não bloquear recursos
legítimos do painel.

### Rate limit

- Limitar requisições repetitivas no login e em endpoints sensíveis.
- Aplicar limites por conta e por origem, evitando depender apenas do IP.
- Não criar bloqueios permanentes que permitam negação de serviço contra
  usuários legítimos.

## Prioridade 3 — Autenticação e sessão

### MFA

- Implementar MFA para contas administrativas.
- Preferir TOTP ou WebAuthn/passkeys.
- Definir códigos de recuperação e processo seguro de perda do segundo fator.
- Auditar ativação, remoção e recuperação do MFA.

### Proteção contra ataques automatizados

- Adicionar atraso progressivo ou limitação de tentativas.
- Detectar força bruta, credential stuffing e password spraying.
- Usar mensagens de erro genéricas no login para evitar enumeração de contas.
- Considerar CAPTCHA apenas como camada complementar após comportamento
  suspeito.

### Sessões PHP

- Usar cookies `Secure`, `HttpOnly` e política `SameSite` adequada.
- Regenerar o identificador da sessão após autenticação.
- Invalidar sessões após troca de senha, desativação da conta ou mudança de
  versão de autenticação.
- Manter tempo de inatividade e expiração absoluta.
- Revisar proteção contra fixação e roubo de sessão.

### Reautenticação

Exigir senha atual ou segundo fator antes de operações de alto impacto, como:

- aplicar regras reais de firewall;
- executar reload;
- alterar usuários administrativos;
- mudar MFA ou credenciais;
- remover configurações críticas.

## Prioridade 4 — Aplicação PHP

- Manter POST e CSRF em todas as operações de alteração.
- Validar entradas no servidor mesmo quando o navegador já valida.
- Usar consultas preparadas no SQLite.
- Escapar toda saída não confiável conforme o contexto HTML.
- Impedir acesso direto a identificadores sem validar autorização.
- Usar mensagens públicas amigáveis e registrar detalhes técnicos somente em
  logs protegidos.
- Revisar upload de arquivos caso essa funcionalidade seja criada.
- Não aceitar comandos arbitrários fornecidos pelo usuário.
- Criar testes de regressão para autenticação, perfis, CSRF e operações
  críticas.

## Prioridade 5 — Privilégio mínimo e sistema operacional

- Executar o servidor web com usuário sem privilégios administrativos.
- Não conceder `sudo` genérico ao usuário do PHP.
- Permitir somente comandos fixos, scripts controlados e argumentos validados
  quando uma operação privilegiada for necessária.
- Separar leitura, validação e aplicação de configurações.
- Restringir permissões do SQLite, segredos, chaves e backups.
- Manter Debian, PHP, servidor web e dependências atualizados.
- Remover módulos, serviços e pacotes desnecessários.
- Avaliar isolamento adicional com AppArmor ou mecanismo equivalente.

## Prioridade 6 — Auditoria, alertas e resposta

- Registrar login bem-sucedido e falho, bloqueios e ações administrativas.
- Registrar usuário, IP, ação, alvo, resultado e horário.
- Não registrar senhas, tokens, chaves privadas ou segredos.
- Definir retenção e rotação dos logs.
- Criar alertas para:
  - muitas falhas de login;
  - acesso administrativo de origem inesperada;
  - alteração de usuário ou MFA;
  - tentativa repetida de ação bloqueada;
  - aplicação futura de regras de firewall;
  - falhas de integridade ou backup.
- Documentar procedimento para revogar sessões, bloquear origem, restaurar
  backup e investigar eventos.

## Prioridade 7 — Camadas complementares

### Fail2Ban

- Pode bloquear origens com comportamento repetitivo detectado em logs.
- Deve usar filtros revisados e tempos de bloqueio graduais.
- Não substitui MFA, rate limit ou correção de vulnerabilidades.

### WAF

- Pode bloquear padrões conhecidos e fornecer virtual patching temporário.
- Exige ajuste e monitoramento para evitar falsos positivos.
- Não deve ser tratado como correção definitiva de falhas no código.

### Proxy reverso ou serviço de borda

- Pode adicionar rate limit, mitigação de bots e proteção contra parte dos
  ataques volumétricos.
- Deve ser avaliado considerando privacidade, custo, dependência externa e
  exposição do IP de origem.

## Sequência sugerida de implantação

1. Inventariar exposição atual, virtual hosts, portas e permissões.
2. Separar painel administrativo e serviços públicos.
3. Restringir o painel por VPN ou lista de IPs.
4. Revisar HTTPS, cookies de sessão e cabeçalhos.
5. Implementar rate limit e proteção do login.
6. Implementar MFA e recuperação segura.
7. Adicionar reautenticação para operações críticas.
8. Revisar privilégio mínimo e sudoers.
9. Criar alertas e procedimento de resposta.
10. Avaliar Fail2Ban, WAF e proxy de borda após as camadas principais.
11. Executar teste de segurança controlado e corrigir os achados.

## Decisões que precisam ser tomadas

- O painel poderá ser acessado somente por VPN?
- Os operadores possuem IPs fixos?
- O site público e o painel usarão hosts separados?
- Qual método de MFA será adotado?
- Qual é o procedimento de recuperação se VPN ou MFA falharem?
- Quais ações exigirão reautenticação?
- Quem receberá alertas e em qual canal?
- Qual será a política de retenção dos logs?
- Será usado WAF local, serviço externo ou nenhum WAF?
- Qual janela de manutenção será usada para atualizações?

## Itens que não devem ser implantados isoladamente

- Fechar o acesso antes de validar um canal administrativo alternativo.
- Ativar HSTS antes de confirmar HTTPS em todos os hosts necessários.
- Aplicar uma CSP restritiva sem observar violações e dependências.
- Criar bloqueio permanente de contas ou IPs sem processo de recuperação.
- Conceder privilégios amplos ao PHP para simplificar automações.
- Considerar WAF ou Fail2Ban como substitutos de correções no código.
- Implementar aplicação real de firewall sem validação, backup e rollback.

## Critérios para uma futura fase de implementação

Cada mecanismo deverá ter:

- escopo e ameaça tratada;
- plano de implantação e reversão;
- teste em ambiente controlado;
- mensagens operacionais claras;
- auditoria;
- documentação de recuperação;
- validação de que não bloqueia acesso legítimo;
- commit e changelog próprios.

## Referências

- OWASP Authentication Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html
- OWASP Session Management Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- OWASP Transport Layer Security Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Security_Cheat_Sheet.html
- OWASP Logging Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
- OWASP Virtual Patching Cheat Sheet:
  https://cheatsheetseries.owasp.org/cheatsheets/Virtual_Patching_Cheat_Sheet.html

## Estado deste handoff

- Documento de avaliação futura.
- Nenhuma proteção foi aplicada.
- Nenhuma porta ou regra foi alterada.
- Nenhum serviço foi reiniciado.
- Nenhum código PHP funcional foi modificado.
