# Histórico de ajustes de interface

## Perfil e navegação

Ajustes concluídos na tela de perfil:

- sidebar sincronizada com a navegação utilizada na dashboard;
- ícones da sidebar padronizados em SVG;
- contexto de autorização da navegação preservado na tela de perfil;
- novos avatares vetoriais “Ogro verde” e “Jegue”;
- seleção, persistência e exibição dos novos avatares;
- botão de alternância de tema corrigido para usar os SVGs nativos;
- lua exibida no tema claro e sol preservado no tema escuro;
- centralização dos ícones dentro do botão de tema;
- cobertura de testes para seleção dos novos avatares.

Validações executadas:

- compilação das views Blade;
- build de produção com Vite;
- `git diff --check`;
- validação da configuração do nginx;
- suíte PHPUnit completa com 41 testes e 115 assertions.
