# Status do projeto — 02/10/2026

Levantamento do que está concluído e do que está pendente, feito para
atualizar o GitHub. Fatos conferidos no servidor de produção em 02/10/2026;
onde algo não foi conferido, está dito.

## Em produção agora

- **Painel 1.3.55**, containers saudáveis (`/up` 200), disco em 58%.
- **Agentes 0.14.0** nos dois servidores autoritativos, com contato recente.
- **Backup local do banco** diário (02:30), com limpeza e teste de restauração
  semanal; último dump de 02/10. **Sem cópia fora do servidor** (ver pendências).
- **Limpeza automática de imagens/cache Docker** diária (03:15).
- Operações dos últimos 5 dias, todas bem-sucedidas: 4 `apply_zones`,
  1 descoberta, 2 upgrades de agente, 1 forçar transferência, 5 leituras de log.

## Concluído desde a 1.3.29

| Área | Releases |
| --- | --- |
| Página própria para zonas reversas, com bloco CIDR | 1.3.30 |
| Desativar e excluir empresa (cancelamento de cliente) | 1.3.31, 1.3.47 |
| Remover bloco legado do BIND e publicar num passo só | 1.3.32, 1.3.36, 1.3.38 |
| Descoberta: comparação entre servidores, zonas ignoradas, aviso de descoberta velha | 1.3.33–1.3.35 |
| NS duplicados no apex; alerta de NS repetido | 1.3.37, 1.3.41 |
| Secundário aplica depois do primário; mensagens claras ao publicar | 1.3.39, 1.3.40, 1.3.42 |
| Configurações → Backup (credenciais do R2 no painel, criptografadas, com teste) | 1.3.43 |
| Agente 0.10.2, limite de API por agente, confirmação de upgrade | 1.3.44–1.3.46 |
| Bloqueio de agentes órfãos; firewall somente leitura (inventário) | 1.3.48, 1.3.49 |
| Dashboard com indicadores semânticos e rodapé da release | 1.3.50, 1.3.51 |
| DISC-001: falso conflito por grafia de nome DNS (canonização no modelo + comando de reconciliação) | 1.3.52 |
| Limpeza automática de disco; importar e configurar zonas em lote | 1.3.53 |
| Log do BIND no painel; forçar transferência de zona (agente 0.14.0) | 1.3.54 |
| Auditoria de lógica: retenção dos eventos dos agentes, alerta de secundário sem a zona, backup que não falha em silêncio | 1.3.55 |

Itens que outras sessões entregaram sem que eu os tivesse pedido, conferidos
no código: alerta de secundário atrasado no Dashboard e restauração de versão
de zona.

**Testes:** última execução completa em 28/09/2026 — 410 (PHP) e 165 (Python)
verdes. Não houve mudança de código depois disso; não foram reexecutados em 02/10.

## Pendente

### Decisão sua
1. **Cópia do backup no Cloudflare R2.** Deixada de fora por escolha. Falta:
   token novo (só o bucket de backup, leitura/gravação de objetos), preencher a
   tela Configurações → Backup, criar `/etc/dns-center/backup.pass` e guardar uma
   cópia dela fora do servidor, e rodar um backup para ver o `.gpg` no bucket.
   O token antigo teve as chaves expostas numa captura de tela — **não foi
   confirmado que foi apagado na Cloudflare**.
2. **GitHub:** enviado em 02/10/2026 (sem force): as tags `v1.3.44`–`v1.3.54` e depois a
   `main` com a `v1.3.55`. O repositório é **público** — ver "Riscos antes de enviar".
3. **Branch antiga `cleanup/estrutura-limpa`** (29/06/2026), no remoto, com
   histórico divergente da `main` (219 commits só nela). Arquivar ou apagar; não
   mexi.

### Higiene
- Feito em 02/10/2026: o Account ID real do R2 e o hostname de um cliente antigo
  saíram dos testes (valores fictícios). O que já foi publicado no histórico não muda.
- `trevizamnetwork.com.br` aparece em docs e em 3 arquivos de código (padrão da URL do
  painel no instalador/agente): é o domínio da própria empresa e é funcional; mantido.
- Dois commits do remoto (`d9c60f6`, `627d552`) ainda contêm, no histórico, o nome de um
  cliente antigo (2 linhas em cada). O estado atual (HEAD) está limpo.

### Produto
- **Firewall via agente, Fase 1 (inventário somente leitura):** conferido em produção em
  02/10/2026 — os dois agentes reportam `status: absent` (nftables disponível, tabela
  `inet dns_center` ausente), com horário de observação atual e sem nenhuma regra criada.
  É o resultado esperado do roteiro (passo 4). **Não testado:** o passo 6 (host com a tabela
  presente) e as fases seguintes de `docs/PROPOSTA_FIREWALL_VIA_AGENT_2026_09_19.md`,
  que não estão implementadas.
- Perfil de nameservers padrão está "Não definido" (só sugere ao criar zona); é uma opção
  de dados (Nameservers → Perfis), não de código.
- Resultado de "Forçar transferência" traz `zone_state` vazio — provavelmente o
  `rndc zonestatus` do BIND 9.20 não imprime essa linha. **Mantido de propósito:** a tela não
  usa o campo e corrigir exigiria uma nova versão do agente e um upgrade nos servidores.
  Entra junto com a próxima mudança do agente.
- `TRUSTED_PROXIES` vazio gera aviso no deploy (correto sem proxy na frente).
- Resolvido: o alerta de SOA `retry > refresh` nas reversas importadas não aparece mais
  (nenhuma zona está nessa condição hoje).

### Fora de escopo, por decisão
- Painel de estatísticas para o cliente (descartado — estatística fica no Zabbix).
- Firewall e fail2ban no painel (descartados).
- Não existe alerta ativo (e-mail/Zabbix): disco e backup só aparecem em log e
  na tela de Configurações. Não há SMTP configurado nem agente Zabbix no host.
- "Testar transferência" (AXFR) como diagnóstico separado não foi construído;
  só "forçar".

## Riscos antes de enviar ao GitHub (repositório público)

- No estado atual (HEAD) **não há** IPs, hostnames ou domínios dos clientes
  reais, nem chaves/tokens, nem arquivo `.env` rastreado.
- O histórico já publicado ainda tem as 2 menções acima ao cliente antigo.
  Enviar os 4 commits novos não piora isso; apagar do histórico exigiria
  reescrever e forçar o envio (`push --force`), o que reescreve a `main`
  pública — só faria sob ordem explícita.
- Enviar tags não altera commits.
