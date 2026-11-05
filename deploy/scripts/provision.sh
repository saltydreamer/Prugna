#!/usr/bin/env bash
# One-time (idempotent) server preparation. Runs as root from the "Provision server" workflow.
# Usage: APP_DOMAIN=dev.example.com bash provision.sh
set -euo pipefail

: "${APP_DOMAIN:?APP_DOMAIN is required}"

APP_DIR=/srv/boilerplate
DEPLOY_USER=deploy
export DEBIAN_FRONTEND=noninteractive

step() { printf '\n==> %s\n' "$*"; }
random() { openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (VPS_USER=root)." >&2
    exit 1
fi

step "System packages and automatic security updates"
apt-get update -q
apt-get install -y -q ca-certificates curl openssl ufw cron unattended-upgrades
systemctl enable --now unattended-upgrades cron

step "Docker"
if ! command -v docker > /dev/null 2>&1; then
    curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker
docker --version
docker compose version

step "Swap (2 GB)"
if [ "$(swapon --show --noheadings | wc -l)" -eq 0 ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile > /dev/null
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
    sysctl -q -p /etc/sysctl.d/99-swappiness.conf
fi
swapon --show

step "User '$DEPLOY_USER' (runs the containers, no root privileges)"
id "$DEPLOY_USER" > /dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
usermod -aG docker "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
# Authorize only the GitHub Actions key (see the README), not every root key.
if grep -q 'github-actions-boilerplate' /root/.ssh/authorized_keys; then
    grep 'github-actions-boilerplate' /root/.ssh/authorized_keys > "/home/$DEPLOY_USER/.ssh/authorized_keys"
else
    cp /root/.ssh/authorized_keys "/home/$DEPLOY_USER/.ssh/authorized_keys"
fi
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"

step "Firewall (SSH, HTTP, HTTPS)"
ssh_port="$(echo "${SSH_CONNECTION:-}" | awk '{print $4}')"
ssh_port="${ssh_port:-22}"
ufw allow "$ssh_port/tcp" > /dev/null
ufw allow 80/tcp > /dev/null
ufw allow 443/tcp > /dev/null
ufw allow 443/udp > /dev/null
ufw --force enable > /dev/null
ufw status | sed -n '1,12p'

step "Application directory $APP_DIR"
install -d -m 750 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR" "$APP_DIR/backups"

if [ ! -f "$APP_DIR/.env" ]; then
    cat > "$APP_DIR/.env" <<ENV
APP_NAME=Prugna
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_DOMAIN=${APP_DOMAIN}
APP_URL=https://${APP_DOMAIN}
# First super admin, created by the first deploy (password printed in the Deploy log).
APP_ADMIN_EMAIL=admin@${APP_DOMAIN}

APP_LOCALE=it
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=it_IT

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=boilerplate
DB_USERNAME=boilerplate
DB_PASSWORD=$(random 32)
DB_ROOT_PASSWORD=$(random 32)

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log

# Emails are written to the log until an SMTP server is configured.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@${APP_DOMAIN}
MAIL_FROM_NAME=Prugna

FORTIFY_REGISTRATION=false
INERTIA_SSR_ENABLED=false
ENV
    echo ".env created with random secrets."
else
    echo ".env already exists: left untouched."
fi
chown "$DEPLOY_USER:$DEPLOY_USER" "$APP_DIR/.env"
chmod 600 "$APP_DIR/.env"

step "Nightly database backup (03:30)"
cat > /etc/cron.d/boilerplate-backup <<CRON
30 3 * * * $DEPLOY_USER [ -x $APP_DIR/backup.sh ] && $APP_DIR/backup.sh >> $APP_DIR/backups/backup.log 2>&1
CRON
chmod 644 /etc/cron.d/boilerplate-backup

step "Done"
echo "Server ready. Next: run the Deploy workflow."
