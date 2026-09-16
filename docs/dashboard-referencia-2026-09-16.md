# Dashboard baseado na referência de 16/09/2026

## Objetivo e escopo

Reproduzir na página inicial a composição, a hierarquia visual, as cores e a densidade da imagem `ChatGPT Image 16 de set. de 2026, 19_18_54.png`. A sidebar ficou fora do escopo e seu HTML e CSS não foram alterados.

## Implementação

- O cabeçalho recebeu a mesma hierarquia da referência: identificação da central, título, descrição curta, horário de atualização, resumo de saúde e atalho para a página de servidores. `Ctrl+K` e `⌘K` também abrem esse inventário.
- A faixa de saúde destaca o estado real do ambiente e resume servidores online, zonas divergentes e pendências.
- Os quatro indicadores mostram servidores DNS, zonas autoritativas, serviços autoritativos disponíveis e pendências. As barras usam proporções calculadas dos dados existentes. O indicador de serviços considera a observação autoritativa recente e a disponibilidade reportada.
- A grade operacional tem três painéis: mapa, estado dos servidores e pendências. O mapa usa um SVG local do Brasil com 27 divisões estaduais, gerado a partir dos dados vetoriais públicos da [Natural Earth](https://www.naturalearthdata.com/downloads/50m-cultural-vectors/). Os pontos e conexões sobrepostos são decorativos e usam a cor do estado dos primeiros servidores reais; não representam sua posição geográfica. A lista ao lado usa os nomes e estados reais da organização.
- O painel de servidores conserva nome, hostname, função, estado e a informação de heartbeat. As pendências continuam vindo de status de servidor, observações autoritativas e publicações. Cada linha mostra o horário relativo do último evento disponível.
- A faixa inferior reúne os contadores de DNS autoritativo, as operações recentes e os atalhos. O link para auditoria aparece apenas para quem pode acessar essa área.
- Foram adicionados estados vazios e regras responsivas para telas menores.
- As alterações de estilo foram limitadas aos seletores do dashboard na área principal.

## Origem dos dados

Todos os números exibidos são consultados no contexto da organização atual. A imagem é uma referência de aparência; os números que aparecem nela não foram copiados para a aplicação. A sidebar existente continua independente do novo layout.

## Arquivos

- `resources/views/dashboard/index.blade.php`: estrutura e apresentação dos dados.
- `resources/views/components/dashboard-icon.blade.php`: ícones vetoriais usados nos indicadores e painéis.
- `resources/css/app.css`: estilos e regras responsivas do dashboard.
- `public/images/brazil-map.svg`: contorno e divisões estaduais do mapa, servidos localmente.
- `ChatGPT Image 16 de set. de 2026, 19_18_54.png`: imagem fornecida como referência.

## Verificação executada

- `npm run build`: compilação de produção concluída.
- `php artisan view:cache`: templates Blade compilados.
- `AuthenticationDashboardTest` e `DnsAuthoritativeObservationTest`: 20 testes passaram, com 110 asserções. Os testes cobrem isolamento por organização, contadores reais, pendências, estado vazio e atalhos de usuário somente leitura.
- `git diff --check`: sem erros de espaços.

A comparação visual em navegador deve ser feita em tema escuro, nas larguras de desktop e mobile. Em desktop, a linha superior tem mapa, servidores e pendências, nessa ordem; a inferior tem DNS autoritativo, atividade recente e ações rápidas. A validação automatizada confirmou compilação e conteúdo, mas não mede semelhança pixel a pixel.

## Limitações visuais

O mapa é ilustrativo. A busca no cabeçalho leva ao inventário de servidores; a aplicação ainda não possui busca global. As ações rápidas levam às páginas de gestão correspondentes, como já ocorria antes da mudança.
