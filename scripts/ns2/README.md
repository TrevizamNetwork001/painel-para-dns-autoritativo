# Agente DNS nativo V3

Este diretório contém o pacote remoto instalado pelo painel em servidores DNS Debian/BIND9.
No V3, a adoção e o provisionamento são iniciados pela tela `dns-servers.php`; o operador informa o usuário administrativo temporário e o painel cria o usuário restrito `dns-sync`, instala a chave, copia scripts, aplica o sudoers final e valida o BIND.

## Fluxos suportados

### Adotar servidor existente

O servidor remoto deve ser Debian, ter SSH ativo e já possuir BIND instalado. O painel executa:

- teste SSH administrativo;
- detecção de Debian;
- detecção de BIND/named-checkconf;
- criação/ajuste do usuário `dns-sync`;
- instalação da chave pública do painel com forced command;
- instalação dos scripts em `/usr/local/bin`;
- instalação de `/etc/sudoers.d/dns-sync`;
- garantia dos diretórios `/var/cache/bind/slave-aut` e `/var/cache/bind/slave-rev`;
- criação de novas zonas slave diretamente em `/etc/bind/named.conf.local`;
- validação por `dns-slave-status.sh`.

### Provisionar servidor novo

O servidor remoto deve ser Debian limpo, com SSH ativo e IPv4 configurado. Além das etapas de adoção, o painel instala:

- `bind9`
- `bind9utils`
- `dnsutils`
- `sudo`
- `nftables`
- `fail2ban`

Depois executa `named-checkconf` e `systemctl enable --now bind9`.

## Sudoers final

O arquivo final permitido para `dns-sync` é `dns-sync.sudoers` e restringe execução a:

```text
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-slave-add-zone.sh
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-slave-remove-zone.sh
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-slave-reload.sh
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-slave-migrate-layout.sh
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-slave-status.sh
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-slave-check-transfer.sh
dns-sync ALL=(root) NOPASSWD: /usr/local/bin/dns-zone-inventory.sh
```

`dns-sync ALL=(ALL) NOPASSWD: ALL` permanece proibido.

## Chave SSH local

O painel usa caminhos fixos:

- chave privada: `/var/www/.ssh/id_ed25519_dns_sync`
- chave pública: `/var/www/.ssh/id_ed25519_dns_sync.pub`
- host keys: `/var/www/.ssh/known_hosts`

Se a chave local ainda não existir, o painel tenta gerá-la automaticamente com `ssh-keygen`. As credenciais administrativas informadas na tela são transitórias e não são gravadas na tabela `dns_servers`.

## Scripts instalados no servidor remoto

- `dns-slave-status.sh`
- `dns-slave-reload.sh`
- `dns-slave-check-transfer.sh`
- `dns-slave-add-zone.sh`
- `dns-slave-remove-zone.sh`
- `dns-slave-migrate-layout.sh`
- `dns-zone-inventory.sh`
- `dns-sync-command.sh`

O script `install-ns2-agent.sh` continua disponível para atualização/compatibilidade com servidores já preparados, mas o caminho principal do V3 é o bootstrap web em `dns-servers.php`.

## Layout slave esperado

Zonas slave sao declaradas diretamente em `/etc/bind/named.conf.local`.

- zonas autoritativas usam `file "/var/cache/bind/slave-aut/NOME_DA_ZONA.hosts"`;
- zonas reversas `in-addr.arpa` e `ip6.arpa` usam `file "/var/cache/bind/slave-rev/NOME_DA_ZONA.rev"`.

O script `dns-slave-migrate-layout.sh` corrige servidores que ainda tenham o layout legado em `/etc/bind/named.conf.slaves`. Ele faz backup, remove o include legado de `named.conf.local`, recria os blocos no layout esperado, roda `named-checkconf` e executa `rndc reconfig`. Ele nao apaga `/etc/bind/named.conf.slaves` nem `/var/cache/bind/slaves`.
