# Dashboard Operacional final — 17/09/2026

Este documento registra a evolução da dashboard aprovada em 16/09 até a versão
publicada em 17/09. A [implementação inicial](dashboard-referencia-2026-09-16.md)
permanece como histórico da referência visual; suas descrições do mapa, da
lupa, do horário de atualização e do tema escuro não descrevem a versão atual.

## Auditoria do mapa

O antigo “Mapa da infraestrutura” era **decorativo (categoria C)**. O Blade
ordenava os servidores por nome e atribuía aos três primeiros, nessa ordem,
os pontos fixos `(95,205)`, `(205,224)` e `(295,78)` sobre o SVG do Brasil.
Na lista vista na auditoria, ns1, ns2 e dns-primary recebiam pontos; dns-secondary
aparecia apenas na lista lateral. As conexões também eram fixas. O modelo e
as migrations de `dns_servers` não têm cidade, região, endereço, datacenter,
latitude, longitude ou campo equivalente. IP e hostname não foram usados para
inferir localização.

O mapa foi substituído pela **Topologia da infraestrutura**. Ela agrupa
servidores pelos papéis cadastrados `primary`, `secondary` e `standalone`, e
mostra nome e estado real em texto e cor. As linhas ligam o título aos grupos;
não representam transferência entre servidores. O SVG geográfico antigo
continua no repositório como artefato histórico, mas não é renderizado na
dashboard. Não foram criados dados geográficos ou consultas externas.

## Composição atual

- O header conserva título, descrição, empresa atual, troca de empresa, tema
  e menu do usuário. A lupa e o atalho Ctrl+K foram removidos. O texto
  “Última atualização” foi retirado porque mostrava `now()` da renderização,
  sem medir a atualização dos sistemas.
- A faixa de saúde e os quatro KPIs continuam usando dados da organização
  atual. A primeira linha contém topologia, estado dos servidores e
  pendências, com alturas externas alinhadas no desktop. Quando há poucas
  pendências, as linhas existentes ocupam o espaço sem conteúdo inventado;
  em telas menores, o painel volta à altura natural.
- O painel de servidores mostra nome, hostname, último contato e badges de
  função e estado lado a lado. Percentuais de CPU, memória e uptime do mockup
  não foram copiados: o inventário não fornece esses percentuais como métricas
  operacionais atuais.
- Atividade recente usa SVGs do conjunto da dashboard para o tipo de operação
  e um ícone de alerta para falha ou expiração. O texto mantém o resultado
  acessível sem depender da cor.
- DNS Autoritativo, Atividade recente e Ações rápidas preservam a estrutura
  aprovada. A dashboard segue responsiva e permite rolagem natural.

## Tema claro

O tema escuro da dashboard usava cores fixas e por isso não acompanhava a
troca de tema global. Regras limitadas a `html[data-theme='light'] .dashboard-v2`
agora adaptam fundo, cards, faixa de saúde, topologia, textos, badges,
contadores, atividade e ações rápidas. O tema escuro permanece com suas cores
anteriores; a sidebar continua usando as variáveis globais. O estado também é
indicado por texto, além de cor.

## Código, validação e limites

- `resources/views/dashboard/index.blade.php`: dados e composição dos painéis.
- `resources/css/app.css`: layout responsivo e cores dos dois temas.
- `resources/js/app.js`: remoção do atalho associado à lupa.
- `tests/Feature/AuthenticationDashboardTest.php`: assegura que a topologia
  usa papéis e estados cadastrados e não afirma localização inexistente.

Os testes focados passaram com **8 testes e 45 assertions**. A suíte Laravel
completa passou com **301 testes e 1.530 assertions** após os ajustes de
estrutura. Pint, lint PHP, `view:cache`, `route:list`, build Vite e
`git diff --check` passaram nos gates aplicáveis. Os ajustes posteriores de
composição e tema passaram novamente nos testes focados e no build Vite.
Não havia navegador headless disponível para capturas automáticas em
1366×768 e 1440×900; a validação de layout foi feita por revisão de HTML/CSS
e pela observação do operador na interface publicada.

## Commits e publicação

| Commit | Alteração |
| --- | --- |
| `edff81a` | Remove mapa sem localização e cria topologia factual |
| `914a5c3` | Usa SVGs em Atividade recente |
| `9f08d69` | Alinha Estado dos servidores à referência |
| `f356a64` | Alinha a altura dos três painéis operacionais |
| `4f6d143` | Remove a lupa do header |
| `df42a9c` | Aplica tema claro à área principal da dashboard |

Os commits foram enviados a `origin/main`. As versões de produção registradas
pelo atualizador foram `1.3.25-dashboard-9f08d69` às 07:49,
`1.3.25-dashboard-4f6d143` às 08:01 e
`1.3.25-dashboard-df42a9c` às 08:09, no horário de São Paulo. O comando
`deploy/dns-center-deploy update` executou preflight, backup validado,
checagem de migrations e health checks a cada atualização. Nenhuma migration
nova foi criada.

O build padrão da imagem PHP falhou na instalação remota do pacote Redis pelo
PECL. Como as alterações desde a imagem anterior eram apenas de interface,
as imagens publicadas foram derivadas das imagens locais já ativas e receberam
somente o Blade, o CSS, o JavaScript e os assets Vite correspondentes. Não
houve alteração de dependências, agente ou core DNS. Na versão final,
app, web, fila e scheduler ficaram saudáveis; `/up` e o CSS versionado
responderam HTTP 200. A versão ativa registrada é
`1.3.25-dashboard-df42a9c`.
