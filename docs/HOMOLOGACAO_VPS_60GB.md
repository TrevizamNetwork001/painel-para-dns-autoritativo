# Homologação em VPS de 60 GB

Roteiro para validar a branch de desenvolvimento do DNS Center em um ambiente
isolado. Este documento não autoriza publicação em produção.

## Princípios de segurança

- Usar uma VPS dedicada à homologação, com Debian compatível e cerca de 60 GB.
- Não copiar `.env`, banco, certificados, chaves TSIG, tokens ou backups de produção.
- Não apontar nameservers públicos, agentes operacionais ou IPs de produção para a VPS.
- Usar domínios reservados para teste, dados fictícios e credenciais exclusivas.
- Manter firewall restritivo e acesso administrativo por chave SSH própria do ambiente.
- Reservar espaço para imagens atual/anterior, volumes, backup e rollback; não contornar o precheck.

## 1. Preparar a VPS

1. Atualizar o Debian e instalar somente os pacotes aprovados para Docker Engine,
   Docker Compose, Git e certificados HTTPS.
2. Criar os diretórios externos definidos em `deploy/deployment.env.example`.
3. Instalar o Disk Guard e os agendamentos conforme `deploy/README.md`.
4. Confirmar capacidade com `df -h` e executar o precheck antes de pulls ou builds.
5. Configurar monitoramento de disco, containers, HTTPS e disponibilidade da aplicação.

## 2. Obter o código isolado

```bash
git clone https://github.com/TrevizamNetwork001/painel-para-dns-autoritativo.git
cd painel-para-dns-autoritativo
git fetch origin feature/dns-center-ux-ops
git switch --track origin/feature/dns-center-ux-ops
git status --short
```

Registrar o hash testado com `git rev-parse HEAD`. Não fazer merge com `main` durante
a homologação.

## 3. Configurar o ambiente de teste

1. Criar arquivos de ambiente fora do repositório a partir dos modelos em `deploy/`.
2. Gerar `APP_KEY`, senhas PostgreSQL/Redis e certificados exclusivos de homologação.
3. Usar nomes de imagens e rede que não coincidam com produção.
4. Configurar hostnames internos ou um subdomínio de teste sem delegação DNS pública.
5. Conferir que arquivos reais de ambiente estejam com modo `0600` e ignorados pelo Git.

## 4. Banco e dados fictícios

1. Criar PostgreSQL e Redis exclusivos do ambiente.
2. Executar migrations somente no banco de homologação.
3. Popular empresas, usuários, domínios, registros, servidores e estados fictícios.
4. Cobrir listas vazias, textos longos, permissões administrativas e não administrativas.
5. Nunca restaurar um dump de produção nesta fase inicial.

## 5. Dependências, testes e assets

1. Instalar dependências PHP e JavaScript com os lockfiles do repositório.
2. Executar os testes Laravel e as validações estáticas disponíveis.
3. Compilar os assets Vite e confirmar a geração e leitura do manifesto.
4. Construir as imagens somente após `deploy/disk-precheck.sh` retornar `PRECHECK_OK`.
5. Registrar versões das ferramentas, hashes das imagens e consumo de disco antes/depois.

## 6. Homologação funcional e visual

Validar em Chromium, nos temas dark e light, as resoluções 1920x1080, 1440x900,
1366x768, 1024x768, 768x1024 e 390x844. Cobrir dashboard, servidores,
nameservers, domínios, registros, zonas reversas, auditoria, usuários, empresas,
configurações, perfil e telas do agente.

Para cada tela, conferir listas preenchidas e vazias, textos longos, modais,
erros de formulário, navegação por teclado, contraste, rolagem interna das tabelas
e ausência de overflow horizontal na página.

Também validar CSRF, permissões, métodos HTTP, pesquisa, filtros, criação e edição
com dados fictícios. Operações de publicação DNS devem usar apenas BIND e agentes
descartáveis, em rede isolada e sem transferência para servidores públicos.

## 7. Backup e rollback

1. Criar um backup do banco de homologação e executar uma restauração de prova.
2. Validar o armazenamento externo com credenciais exclusivas de teste, se aplicável.
3. Manter as imagens imutáveis da versão candidata e da versão anterior.
4. Ensaiar rollback no ambiente isolado e confirmar aplicação, web, fila, scheduler,
   PostgreSQL e Redis saudáveis após a reversão.
5. Registrar tempo de indisponibilidade, consumo máximo de disco e evidências.

## Critérios de saída

A branch somente pode seguir para planejamento de release após testes Laravel,
build Vite, homologação dark/light, fluxos DNS isolados, backup e rollback aprovados.
Falha de precheck, falta de espaço, segredo de produção ou integração com agente/DNS
operacional interrompe a homologação até correção segura.
