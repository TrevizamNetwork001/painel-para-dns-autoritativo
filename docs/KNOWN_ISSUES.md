# Pendências técnicas conhecidas

Este documento registra comportamentos confirmados que ainda precisam de
correção no produto. Cada item deve permanecer aqui até existir implementação,
teste de regressão e release publicada.

## DISC-001 — falso conflito por nomes DNS equivalentes com e sem ponto final

**Estado:** aberto  
**Detectado em:** 20/09/2026, painel 1.3.51  
**Área:** descoberta de zonas BIND

### Sintoma

A descoberta termina com sucesso, mas uma zona gerenciada aparece como
`Conflito` mesmo quando o serial descoberto coincide com o serial cadastrado.

### Causa confirmada

`DnsBindRuntimeController::computeComparisonState()` compara somente o serial e
a quantidade bruta de registros. O banco pode conter o mesmo owner name em
grafias DNS equivalentes, por exemplo `ns1.example.com` e
`ns1.example.com.`. Essas linhas são contadas separadamente no painel, enquanto
o arquivo BIND efetivo contém apenas uma ocorrência canônica.

O caso observado em produção tinha:

- serial descoberto e cadastrado: `2026091807`;
- 14 registros descobertos no BIND;
- 18 linhas cadastradas no painel;
- quatro linhas extras: A e AAAA de `ns1` e `ns2`, duplicadas apenas pela
  presença/ausência do ponto final.

O serviço DNS continuou correto; o problema estava na comparação e na
integridade dos dados do painel.

### Correção recomendada

1. Definir uma representação canônica única para owner names e conteúdos que
   sejam nomes DNS: caixa normalizada e exatamente um ponto final quando FQDN.
2. Aplicar a canonicalização na validação e persistência de registros, não
   apenas na apresentação.
3. Impedir novas duplicatas semânticas no backend, inclusive em importações,
   sincronização de nameservers e edição manual.
4. Comparar conjuntos de registros canonicalizados na descoberta; não decidir
   conflito apenas pela contagem bruta.
5. Criar migração/comando de reconciliação que liste e remova duplicatas
   existentes com confirmação explícita, preservando uma linha e registrando
   auditoria.

### Testes de regressão exigidos

- `host.example` e `host.example.` com mesmo tipo/conteúdo são equivalentes;
- diferenças reais de serial ou conteúdo continuam marcadas como conflito;
- registros repetidos não são criados por importação, perfil de nameservers ou
  edição manual;
- a reconciliação é idempotente e isolada por organização e zona;
- uma nova descoberta do caso reconciliado retorna `Existente`.

### Contorno operacional atual

Revisar as linhas duplicadas na zona, manter uma única forma canônica, publicar
e executar nova descoberta. A remoção deve ocorrer pela interface ou por um
procedimento aprovado com backup e auditoria; a descoberta em si é somente
leitura e não corrige os registros.
