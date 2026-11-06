#!/usr/bin/env bash
# One-time (idempotent) server preparation. Runs as root from the "Provision server" workflow.
# Usage: APP_DOMAIN=dev.example.com bash provision.sh
#
# Internal server behind an existing reverse proxy (e.g. a Proxmox LXC): run it by hand as root with
#   INTERNAL=1 APP_DOMAIN=prugna.example.com [TRUSTED_PROXIES=192.168.1.10] [RUNNER_TOKEN=…] bash provision.sh
# The app then serves plain HTTP on port 80 (the proxy does HTTPS), there is no swap or firewall
# (an LXC container can't set them), and with RUNNER_TOKEN the GitHub Actions runner that deploys
# every merge on main is installed (labels "self-hosted,prugna").
set -euo pipefail

: "${APP_DOMAIN:?APP_DOMAIN is required}"
INTERNAL="${INTERNAL:-0}"
REPOSITORY_URL="${REPOSITORY_URL:-https://github.com/saltydreamer/Prugna}"

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
apt-get install -y -q ca-certificates curl openssl ufw cron unattended-upgrades tar
systemctl enable --now unattended-upgrades cron

step "Docker"
if ! command -v docker > /dev/null 2>&1; then
    curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker
docker --version
docker compose version

step "Swap (2 GB)"
if [ "$INTERNAL" = "1" ]; then
    echo "Internal server: swap is set on the host (Proxmox), not in the container."
elif [ "$(swapon --show --noheadings | wc -l)" -eq 0 ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile > /dev/null
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
    sysctl -q -p /etc/sysctl.d/99-swappiness.conf
fi
swapon --show 2> /dev/null || true

step "User '$DEPLOY_USER' (runs the containers, no root privileges)"
id "$DEPLOY_USER" > /dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
usermod -aG docker "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
# Authorize only the GitHub Actions key (see the README), not every root key.
# An internal server is deployed by its own runner: no SSH key is needed.
if [ -f /root/.ssh/authorized_keys ]; then
    if grep -qE 'github-actions-(boilerplate|prugna)' /root/.ssh/authorized_keys; then
        grep -E 'github-actions-(boilerplate|prugna)' /root/.ssh/authorized_keys > "/home/$DEPLOY_USER/.ssh/authorized_keys"
    elif [ "$INTERNAL" != "1" ]; then
        cp /root/.ssh/authorized_keys "/home/$DEPLOY_USER/.ssh/authorized_keys"
    fi
    if [ -f "/home/$DEPLOY_USER/.ssh/authorized_keys" ]; then
        chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
        chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
    fi
fi

step "Firewall (SSH, HTTP, HTTPS)"
if [ "$INTERNAL" = "1" ]; then
    echo "Internal server: reachable through the reverse proxy; filter port 80 on the Proxmox firewall if needed."
else
    ssh_port="$(echo "${SSH_CONNECTION:-}" | awk '{print $4}')"
    ssh_port="${ssh_port:-22}"
    ufw allow "$ssh_port/tcp" > /dev/null
    ufw allow 80/tcp > /dev/null
    ufw allow 443/tcp > /dev/null
    ufw allow 443/udp > /dev/null
    ufw --force enable > /dev/null
    ufw status | sed -n '1,12p'
fi

step "Application directory $APP_DIR"
install -d -m 750 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR" "$APP_DIR/backups"

if [ ! -f "$APP_DIR/.env" ]; then
    if [ "$INTERNAL" = "1" ]; then
        # Behind the reverse proxy: plain HTTP on port 80, the proxy's X-Forwarded-* headers trusted.
        proxy_settings="APP_SERVER_NAME=:80
TRUSTED_PROXIES=${TRUSTED_PROXIES:-*}"
    else
        proxy_settings=""
    fi
    cat > "$APP_DIR/.env" <<ENV
APP_NAME=Prugna
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_DOMAIN=${APP_DOMAIN}
APP_URL=https://${APP_DOMAIN}
${proxy_settings}
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

if [ "$INTERNAL" = "1" ] && [ -n "${RUNNER_TOKEN:-}" ]; then
    step "GitHub Actions runner (deploys every merge on main)"
    RUNNER_DIR="/home/$DEPLOY_USER/actions-runner"
    if [ ! -f "$RUNNER_DIR/.runner" ]; then
        version="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest | grep -m1 '"tag_name"' | sed -E 's/.*"v([^"]+)".*/\1/')"
        case "$(uname -m)" in
            aarch64 | arm64) arch=arm64 ;;
            *) arch=x64 ;;
        esac
        install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$RUNNER_DIR"
        curl -fsSL "https://github.com/actions/runner/releases/download/v${version}/actions-runner-linux-${arch}-${version}.tar.gz" | tar -xz -C "$RUNNER_DIR"
        chown -R "$DEPLOY_USER:$DEPLOY_USER" "$RUNNER_DIR"
        "$RUNNER_DIR/bin/installdependencies.sh" > /dev/null
        sudo_deploy() { su -s /bin/bash -c "$*" "$DEPLOY_USER"; }
        sudo_deploy "cd '$RUNNER_DIR' && ./config.sh --unattended --replace --url '$REPOSITORY_URL' --token '$RUNNER_TOKEN' --name '$(hostname)' --labels prugna --work _work"
        (cd "$RUNNER_DIR" && ./svc.sh install "$DEPLOY_USER" > /dev/null && ./svc.sh start > /dev/null)
        echo "Runner v${version} installed and started as '$DEPLOY_USER'."
    else
        echo "Runner already configured."
    fi
fi

step "Done"
if [ "$INTERNAL" = "1" ]; then
    echo "Server ready. Next: point the reverse proxy for ${APP_DOMAIN} to http://$(hostname -I | awk '{print $1}'):80,"
    echo "then set the repository variable INTERNAL_DEPLOY=true and run the Deploy workflow."
else
    echo "Server ready. Next: run the Deploy workflow."
fi
