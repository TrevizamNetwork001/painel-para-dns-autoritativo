# Handoff — Ajuste de UX do agente

Data: 18/06/2026

## Objetivo

Concentrar diagnóstico, configuração do agente e manutenção no modal `Ferramentas`, eliminando a necessidade de abrir um segundo modal para gerenciar o agente remoto.

## Arquivo alterado

- `dns-servers.php`

## Implementação

- `Gerenciar agente` passou a usar um painel expansível na seção `AGENTE` do modal `Ferramentas`.
- Os atalhos `Gerenciar agente` e `Atualizar agente` abrem o modal `Ferramentas` diretamente com o painel do agente expandido.
- Foram mantidos os campos existentes:
  - Usuário administrativo;
  - Método de autenticação;
  - Senha SSH;
  - Senha sudo/root alternativa;
  - Chave SSH administrativa;
  - Data da credencial salva.
- A data da credencial é apresentada em campo somente leitura, com `Nunca` quando não existe credencial salva.
- Foram preservados os handlers POST:
  - `atualizar` para `Salvar configuração`;
  - `atualizar_agente_admin` para `Atualizar agente`.
- Os formulários enviam `return_modal` e `return_section`, permitindo reabrir o modal `Ferramentas` após o redirect POST/Redirect/GET.
- Após salvar ou atualizar:
  - o servidor correto permanece selecionado;
  - o modal `Ferramentas` é reaberto;
  - o painel do agente permanece expandido;
  - a seção `AGENTE` é posicionada na área visível;
  - o retorno é apresentado em `Resultado`;
  - a operação do agente também apresenta mensagem local de sucesso ou erro.
- Resultados de falha retornados ao modal não são mais duplicados no painel principal da página.
- Não existem referências ativas ao modal antigo `Agente remoto - ...` em `dns-servers.php`.

## Escopo preservado

Não foram alterados:

- backend operacional;
- execução SSH;
- criptografia de credenciais;
- esquema ou conteúdo do banco;
- scripts ou instalação do agente;
- lógica DNS/BIND;
- nomes e comportamento dos handlers POST existentes.

## Validações executadas

```text
php -l dns-servers.php
No syntax errors detected in dns-servers.php
```

O JavaScript embutido também foi extraído e validado com:

```text
node --check
```

Resultado: nenhuma falha de sintaxe.

## Homologação recomendada

1. Abrir `Servidores DNS`.
2. No menu `Ações`, clicar em `Gerenciar agente`.
3. Confirmar que apenas o modal `Ferramentas` abre e que o painel do agente já está expandido.
4. Salvar a configuração sem alterar credenciais.
5. Confirmar permanência no modal, toast e resultado local.
6. Executar `Atualizar agente` em um servidor de homologação.
7. Confirmar permanência no modal e saída técnica na seção `Resultado`.
8. Testar o layout em viewport desktop e móvel.

## Observação do ambiente

O diretório fornecido não contém um repositório Git acessível. A revisão foi feita diretamente no arquivo e por validações de sintaxe.
