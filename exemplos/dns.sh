#!/bin/bash

# Tipo de configuração? 1 = Master / 2 = Slave
TIPO=1

# Dominio
DOMAIN=example.com

# IPs Web
WWW_IPV4=192.0.2.250
#WWW_IPV6=2001:db8:cafe::4

# Informe os networks do seu AS IPv4 para cada /24
NETWORKs="192.0.2.0; 198.51.100.0; 203.0.113.0;"

# Informe o network do seu AS IPv6 /32
NETWORKs6="2001:db8::"

# Adicione todos os seus ips que terão permissão a resolução de nomes, incluindo sempre ";" no final
RECURSION_IPV4="192.0.2.0/24;"
RECURSION_IPV6="2001:db8::/32;"
RECURSION_PRIVATE="192.168.0.0/16; 172.16.0.0/12; 100.64.0.0/10; 10.0.0.0/8;"

# Endereço IPs dos DNS
NS1_IPV4=192.0.2.242
NS1_IPV6=2001:db8::242
NS2_IPV4=192.0.2.243
NS2_IPV6=2001:db8::243


# --------------------------------------------------------------------------------------------
VERMELHO='\033[1;31m'
VERDE='\033[1;32m'
AZUL='\033[1;36m'
AMARELO='\033[1;33m'
NC='\033[0m'

if [[ ${TIPO} -eq 1 ]]
  then
    echo -e ${AZUL}"CONFIGURANDO SERVIDOR MASTER"${NC}
  else
    echo -e ${AZUL}"CONFIGURANDO SERVIDOR SLAVE"${NC}
  fi
echo

BIND_DIR=/etc/bind
FAIL2_DIR=/etc/fail2ban
NFT_CONF=/etc/nftables.conf
SERIAL="$(date '+%Y%m%d')00"
REVADDR6=$(echo ${NETWORKs6} | rev | sed 's/://g' | sed 's/.\{1\}/&./g')
REVFILE6=$(echo ${NETWORKs6} | sed 's/://g' | sed 's/.\{4\}/&./g')

echo -e ${AMARELO}"Instalando bind9, nftables e fail2ban..."${NC}
sleep 0.5
apt install bind9 dnsutils nftables fail2ban -y
sleep 0.5
if [[ ! -d "${BIND_DIR}" ]]
  then
        echo -e ${VERMELHO}"ERRO: o bind9 não foi instalado, abortado!"${NC}
        exit 1
  fi
if [[ ! -d "${FAIL2_DIR}" ]]
  then
        echo -e ${VERMELHO}"ERRO: o fail2ban não foi instalado, abortado!"${NC}
        exit 1
  fi
if [[ ! -f "${NFT_CONF}" ]]
  then
        echo -e ${VERMELHO}"ERRO: o nftables não foi instalado, abortado!"${NC}
        exit 1
  fi
echo; echo -n "Criando backup... "
cp /etc/bind/named.conf /etc/bind/named.conf.bkp
cp /etc/bind/named.conf.local /etc/bind/named.conf.local.bkp
cp /etc/bind/named.conf.options /etc/bind/named.conf.options.bkp
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo -n "Criando diretórios e dando permissões... "
mkdir /var/log/named/
chown bind. /var/log/named/
if [ ${TIPO} -eq 1 ]; then
  mkdir /var/cache/bind/master-aut
  mkdir /var/cache/bind/master-rev
  chown bind. /var/cache/bind/master-*
else
  mkdir /var/cache/bind/slave-aut
  mkdir /var/cache/bind/slave-rev
  chown bind. /var/cache/bind/slave-*
fi
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo -n "Criando atalhos..."
ln -s /var/log/named/ /etc/bind/logs
ln -s /usr/share/dns/ /etc/bind/dns
if [ ${TIPO} -eq 1 ]; then
  ln -s /var/cache/bind/master-aut/ /etc/bind/master-aut
  ln -s /var/cache/bind/master-rev/ /etc/bind/master-rev
else
  ln -s /var/cache/bind/slave-aut/ /etc/bind/slave-aut
  ln -s /var/cache/bind/slave-rev/ /etc/bind/slave-rev
fi
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo -n "Ajustando o named.conf"
cat > /etc/bind/named.conf <<- EOF
logging {

    channel security_file {
        file "/var/log/named/security.log" versions 3 size 30m;
        severity dynamic;
        print-time yes;
    };

    channel file_log {
        file "/var/log/named/named.log" versions 2 size 50k;
        severity info;
        print-time yes;
        print-severity yes;
        print-category yes;
    };

    channel errors_syslog {
        syslog daemon;
        severity warning;
    };

    category security { security_file; };
    category dnssec { errors_syslog; };
    category default { file_log; };
    category lame-servers { null; };
    category edns-disabled { null; };
    category resolver { null; };
    category unmatched { null; };

};

//statistics-channels {
//       inet 127.0.0.1  port 58053 allow { 127.0.0.1; ::1; };
//};

include "/etc/bind/named.conf.options";
include "/etc/bind/named.conf.local";
include "/etc/bind/named.conf.default-zones";
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
if [ ${TIPO} -eq 1 ]; then
echo -n "Ajustando named.conf.options... (Master)"
cat > /etc/bind/named.conf.options <<- EOF
acl autorizados {
        127.0.0.1; ::1; ${RECURSION_IPV4} ${RECURSION_IPV6} ${RECURSION_PRIVATE} };
options {
    directory "/var/cache/bind";
    dnssec-enable yes;
    dnssec-validation auto;
    auth-nxdomain no;
    listen-on { any; };
    listen-on-v6 { any; };
    minimal-responses yes;
    allow-recursion { autorizados; };
    allow-query-cache { autorizados; };
    allow-query { any; };
    allow-transfer { ${NS2_IPV4}; ${NS2_IPV6}; };
    also-notify { ${NS2_IPV4}; ${NS2_IPV6}; };
    masterfile-format text;
    version "RR DNS Server";
};
EOF
else # Slave
echo -n "Ajustando named.conf.options... (Slave)"
cat > /etc/bind/named.conf.options <<- EOF
acl autorizados {
        127.0.0.1; ::1; ${RECURSION_IPV4} ${RECURSION_IPV6} ${RECURSION_PRIVATE} };
options {
    directory "/var/cache/bind";
    dnssec-enable yes;
    dnssec-validation auto;
    auth-nxdomain no;
    listen-on { any; };
    listen-on-v6 { any; };
    minimal-responses yes;
    allow-recursion { autorizados; };
    allow-query-cache { autorizados; };
    allow-query { any; };
    allow-transfer { none; };
    masterfile-format text;
    version "RR DNS Server";
};
EOF
fi
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo

if [ ${TIPO} -eq 1 ]; then # Master
echo "Criado arquivo de zona:"
echo -n " >> ${DOMAIN}.hosts"
cat > /var/cache/bind/master-aut/${DOMAIN}.hosts <<- EOF
\$ORIGIN .
\$TTL 86400 ; 1 day
${DOMAIN}        IN SOA  ns1.${DOMAIN}. hostmaster.${DOMAIN}. (
                            ${SERIAL} ; serial
                            900      ; refresh (15 minutes)
                            3600       ; retry (1 hour)
                            2419200    ; expire (4 weeks)
                            300        ; minimum (5 minutes)
                            )

                        NS      ns1.${DOMAIN}.
                        NS      ns2.${DOMAIN}.

                        A       ${WWW_IPV4}
                        AAAA    ${WWW_IPV6}

;                       MX      10 mail.${DOMAIN}.
;                       TXT     "v=spf1 a mx -all"
;                       SPF     "v=spf1 a mx -all"
;$ORIGIN ${DOMAIN}.
;_dmarc                  TXT "v=DMARC1; p=none"
;_domainkey              TXT "t=y; o=~;"
;$ORIGIN _domainkey.${DOMAIN}.
;mail                    TXT "v=DKIM1; k=rsa; p=xxxxxxxxx"

\$ORIGIN ${DOMAIN}.
\$TTL 900   ; 5 minutes

ns1                     A       ${NS1_IPV4}
                        AAAA    ${NS1_IPV6}
hostmaster              A       ${NS1_IPV4}
                        AAAA    ${NS1_IPV6}

ns2                     A       ${NS2_IPV4}
                        AAAA    ${NS2_IPV6}

www                     A       ${WWW_IPV4}
                        AAAA    ${WWW_IPV6}

; Reverso
EOF
CDIRS=$(echo $NETWORKs | tr ";" "\n")
for CDIR in $CDIRS
do
REVADDR=$(for FIELD in 3 2 1; do printf "$(echo ${CDIR} | cut -d '.' -f $FIELD)."; done)
REVFILE=$(echo ${CDIR} | cut -d"." -f1-3)
cat >> /var/cache/bind/master-aut/${DOMAIN}.hosts <<- EOF
\$ORIGIN ${DOMAIN}.
\$GENERATE 0-255 ${REVFILE}.$    A   ${REVFILE}.$

EOF
done
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo
echo "Criado arquivos reversos:"
CDIRS=$(echo $NETWORKs | tr ";" "\n")
for CDIR in $CDIRS
do
REVADDR=$(for FIELD in 3 2 1; do printf "$(echo ${CDIR} | cut -d '.' -f $FIELD)."; done)
REVFILE=$(echo ${CDIR} | cut -d"." -f1-3)
echo -n " >> ${REVFILE}.rev"
cat > /var/cache/bind/master-rev/${REVFILE}.rev <<- EOF
\$ORIGIN .
\$TTL 86400      ; 1 day
${REVADDR}in-addr.arpa IN SOA ns1.${DOMAIN}. hostmaster.${DOMAIN}. (
                    ${SERIAL} ; serial
                    900        ; refresh (15 minutes)
                    3600       ; retry (1 hour)
                    2419200    ; expire (4 weeks)
                    300        ; minimum (5 minutes)
                    )
                NS      ns1.${DOMAIN}.
                NS      ns2.${DOMAIN}.

;\$ORIGIN ${REVADDR}in-addr.arpa.
;2         PTR     ns1.${DOMAIN}.
;3         PTR     ns2.${DOMAIN}.

\$ORIGIN ${REVADDR}in-addr.arpa.
\$GENERATE 0-255 $ PTR ${REVFILE}.$.${DOMAIN}.
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
done

echo -n " >> ${REVFILE6}rev"
cat > /var/cache/bind/master-rev/${REVFILE6}rev <<- EOF
\$ORIGIN .
\$TTL 86400   ; 1 day
${REVADDR6}ip6.arpa IN SOA ns1.${DOMAIN}.${REVADDR6}ip6.arpa. hostmaster.${DOMAIN}.${REVADDR6}ip6.arpa. (
                    ${SERIAL} ; serial
                    900        ; refresh (15 minutes)
                    3600       ; retry (1 hour)
                    2419200    ; expire (4 weeks)
                    300        ; minimum (5 minutes)
                    )
                NS      ns1.${DOMAIN}.
                NS      ns2.${DOMAIN}.
; Exemplo
2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.e.f.a.c.e.b.e.b.${REVADDR6}ip6.arpa. IN PTR ns1.${DOMAIN}.
3.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.e.f.a.c.e.b.e.b.${REVADDR6}ip6.arpa. IN PTR ns2.${DOMAIN}.
4.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.e.f.a.c.e.b.e.b.${REVADDR6}ip6.arpa. IN PTR www.${DOMAIN}.
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo
echo "Chamandos as conf das zonas no named.conf.local (Master)"
cat > /etc/bind/named.conf.local <<- EOF
zone "${DOMAIN}" {
        type master;
        file "/var/cache/bind/master-aut/${DOMAIN}.hosts";
};
EOF
echo -n " >> zone ${DOMAIN}"
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
CDIRS=$(echo $NETWORKs | tr ";" "\n")
for CDIR in $CDIRS
do
REVADDR=$(for FIELD in 3 2 1; do printf "$(echo ${CDIR} | cut -d '.' -f $FIELD)."; done)
REVFILE=$(echo ${CDIR} | cut -d"." -f1-3)
echo -n " >> zone ${REVADDR}in-addr.arpa"
cat >> /etc/bind/named.conf.local <<- EOF
zone "${REVADDR}in-addr.arpa" {
        type master;
        file "/var/cache/bind/master-rev/${REVFILE}.rev";
};
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
done

echo -n " >> zone ${REVADDR6}in-addr.arpa"
cat >> /etc/bind/named.conf.local <<- EOF
zone "${REVADDR6}ip6.arpa" {
        type master;
        file "/var/cache/bind/master-rev/${REVFILE6}rev";
};
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
else # Slave
echo "Chamandos as conf das zonas no named.conf.local (Slave)"
cat > /etc/bind/named.conf.local <<- EOF
zone "${DOMAIN}" {
        type slave;
        file "/var/cache/bind/slave-aut/${DOMAIN}.hosts";
        masters { ${NS1_IPV4}; };
        allow-notify { ${NS1_IPV4}; };
};
EOF
echo -n "zone ${DOMAIN}"
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
CDIRS=$(echo $NETWORKs | tr ";" "\n")
for CDIR in $CDIRS
do
REVADDR=$(for FIELD in 3 2 1; do printf "$(echo ${CDIR} | cut -d '.' -f $FIELD)."; done)
REVFILE=$(echo ${CDIR} | cut -d"." -f1-3)
echo -n "zone ${REVADDR}in-addr.arpa"
cat >> /etc/bind/named.conf.local <<- EOF
zone "${REVADDR}in-addr.arpa" {
        type slave;
        file "/var/cache/bind/slave-rev/${REVFILE}.rev";
        masters { ${NS1_IPV4}; };
        allow-notify { ${NS1_IPV4}; };
};
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
done
echo -n " >> zone ${REVADDR6}in-addr.arpa"
cat >> /etc/bind/named.conf.local <<- EOF
zone "${REVADDR6}ip6.arpa" {
        type slave;
        file "/var/cache/bind/slave-rev/${REVFILE6}rev";
        masters { ${NS1_IPV4}; };
        allow-notify { ${NS1_IPV4}; };
};
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
fi
echo
echo -n "Criando filtro do fail2ban para o bind9..."
cat > /etc/fail2ban/filter.d/bind9.conf <<- EOF
# Fail2Ban filter file for named (bind9.11).
#

[Definition]

# Daemon name
_daemon=named

# Shortcuts for easier comprehension of the failregex

__pid_re=(?:\[\d+\])
__daemon_re=\(?%(_daemon)s(?:\(\S+\))?\)?:?
__daemon_combs_re=(?:%(__pid_re)s?:\s+%(__daemon_re)s|%(__daemon_re)s%(__pid_re)s?:)

#       hostname       daemon_id         spaces
# this can be optional (for instance if we match named native log files)
__line_prefix=(?:\s\S+ %(__daemon_combs_re)s\s+)?

prefregex = ^%(__line_prefix)s( error:)?\s*client( @[0-9a-z]+)? <HOST>#\S+( \([\S.]+\))?: <F-CONTENT>.+</F-CONTENT>$

failregex = ^(view (internal|external): )?query(?: \(cache\))? '.*' denied\s*$
            ^zone transfer '\S+/AXFR/\w+' denied\s*$
            ^bad zone transfer request: '\S+/IN': non-authoritative zone \(NOTAUTH\)\s*$

ignoreregex =

# DEV Notes:
# Author: Rudimar Remontti
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo -n "Ativando filtro bind9..."
cat > /etc/fail2ban/jail.d/bind9.conf <<- EOF
[bind9]
enabled  = true
port     = domain,953
protocol = tcp
logpath  = /var/log/named/security.log
bantime  = 24h
maxretry = 1
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo

echo -n "Alterando o fail2ban para nftables e deixando mais rigoroso..."
sed -i 's/banaction = iptables-multiport/banaction = nftables-allports/' /etc/fail2ban/jail.conf
sed -i 's/banaction_allports = iptables-allports/banaction_allports = nftables-allports/' /etc/fail2ban/jail.conf
sed -i 's/nftables_mode = meta l4proto <protocol>/nftables_mode =/' /etc/fail2ban/action.d/nftables-allports.conf
sed -i 's/blocktype = reject/blocktype = drop/'  /etc/fail2ban/action.d/nftables-common.conf
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
echo
echo -n "Definido o DNS do servidor para localhost em /etc/resolv.conf"
cat > /etc/resolv.conf <<- EOF
nameserver 127.0.0.1
nameserver ::1
EOF
sleep 0.5; echo -n -e ${VERDE}" [ OK ]"${NC}; echo
cat /etc/resolv.conf
echo
echo "Ativando nftables na inicialização..."
systemctl enable nftables
echo
echo "Reiniciando bind9..."
systemctl restart bind9
sleep 0.5;
echo "Reiniciando nftables..."
systemctl restart nftables
sleep 0.5;
echo "Reiniciando fail2ban..."
systemctl restart fail2ban
sleep 0.5;
#echo "Executando: nft list ruleset"
#nft list ruleset
#echo; echo "Executando: fail2ban-client status"
#fail2ban-client status
#echo
#echo echo "Verificando reverso dos NS1 e NS2"
#dig -x ${NS1_IPV4} +short
#dig -x ${NS2_IPV4} +short
#dig ns1.${DOMAIN}. +short
#dig ns2.${DOMAIN}. +short

#server 0.0.0.0/0 { edns-udp-size 1432; };
#server ::/0 { edns-udp-size 1232; };
