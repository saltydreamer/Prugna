#!/usr/bin/env bash
# Read-only inspection of the target server. Changes nothing.
set -u

section() { printf '\n===== %s =====\n' "$1"; }

section "Utente"
id
if [ "$(id -u)" -eq 0 ]; then echo "root: si"; elif sudo -n true 2>/dev/null; then echo "sudo senza password: si"; else echo "sudo senza password: NO"; fi

section "Sistema operativo"
. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"
uname -srm
uptime -p 2>/dev/null || uptime

section "CPU / RAM / swap"
nproc
free -h

section "Disco"
df -h / /var 2>/dev/null | sort -u

section "Docker"
if command -v docker >/dev/null 2>&1; then
    docker --version
    docker compose version 2>/dev/null || echo "docker compose plugin: assente"
    docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}' 2>/dev/null || true
else
    echo "Docker: non installato"
fi

section "Servizi in ascolto (porte TCP)"
if command -v ss >/dev/null 2>&1; then ss -tlnp 2>/dev/null | awk 'NR==1 || /:(22|80|443|3306|8080)\s/'; else netstat -tlnp 2>/dev/null; fi

section "Web server gia presenti"
for svc in nginx apache2 httpd caddy traefik; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then echo "$svc: attivo"; fi
done
echo "(fine elenco)"

section "Firewall"
if command -v ufw >/dev/null 2>&1; then ufw status 2>/dev/null || sudo -n ufw status 2>/dev/null || echo "ufw: stato non leggibile"; else echo "ufw: non installato"; fi
