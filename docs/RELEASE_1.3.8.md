# DNS Center v1.3.8

Data: 2026-09-16

## Escopo

Ajuste de CSS, sem alteração de schema ou lógica de aplicação.
Continuação do fix de tamanho de fonte da v1.3.7: depois do deploy,
comparando print a print com as telas de Domínios e Servidores, o
operador ainda notou vários elementos maiores que o resto do painel na
aba Nameservers — em ambas as sub-abas (Identidades e Perfis).

Causa raiz repetida em cada ponto encontrado: a classe CSS do elemento
não definia `font-size`, então o navegador usava o tamanho padrão dele
(bem maior que o resto do painel, que define tamanho explícito em
praticamente tudo). Não existe reset global de `h1`/`h2`/`h3`/`strong`
no `app.css`, então qualquer classe que "esquecer" de definir o
tamanho fica visualmente destoante — foi exatamente isso que aconteceu
nesta tela específica.

Pontos corrigidos nesta rodada (além dos já feitos na v1.3.7):

- Aba Identidades: contador "N perfil(is)" (1.2rem → 0.74rem/800,
  igual ao contador de registros em Domínios), nome da identidade
  (0.78rem → 0.74rem) e nome do servidor vinculado em "Infraestrutura"
  (sem tamanho nenhum → 0.74rem).
- Aba Perfis: título do perfil (herdava 0.94rem genérico, virou
  0.82rem, mais próximo de um item de lista), contador "N NS"
  (0.78rem → 0.7rem), número de posição na lista de NS (sem tamanho
  nenhum → 0.7rem) e nome/identidade de cada NS (0.72/0.62rem →
  0.74/0.63rem, igual `.domains-name-cell`).
- Cabeçalho de cada seção ("Nameservers cadastrados"/"Perfis de
  nameservers"): 1.1rem → 0.94rem, igual `.domain-section-header h2`
  usado na tela da zona. O parágrafo de descrição logo abaixo não
  tinha tamanho nenhum, agora 0.67rem.
- Rótulos dos cards de resumo no topo da página (ex. "2 ativas", "1
  ativos"): sem tamanho nenhum, agora 0.65rem.

## Testes e gates

- `php artisan test`: 244 testes, 1330 assertions — sem regressão.
- Pint: 166 arquivos aprovados.
- Build do frontend (`vite build`): sem erro, em cada uma das três
  rodadas de ajuste.
- `composer audit`: sem vulnerabilidades.

## Deploy
