# Deploy sul VPS

L'app gira sul VPS in container Docker, aggiornati automaticamente da GitHub Actions.

```
push / merge su main
  └─ Tests ─ test su MariaDB + stile del codice
      └─ Build image ─ immagine Docker → ghcr.io/<owner>/prugna:<commit>
          └─ Smoke test ─ avvia lo stack completo sul runner e controlla le pagine
              └─ Deploy to VPS ─ scarica l'immagine, migra il database, riavvia i container
```

## Sul server

| Container | Ruolo |
| --- | --- |
| `app` | FrankenPHP: PHP 8.4 + Caddy, HTTPS automatico (Let's Encrypt) sulle porte 80/443 |
| `worker` | `queue:work`: invia notifiche ed email in coda |
| `scheduler` | `schedule:work`: attività pianificate |
| `mariadb` | MariaDB 11, dati nel volume `boilerplate_mariadb` (non esposto all'esterno) |

Tutto si trova in `/srv/boilerplate` (utente `deploy`):

- `compose.yaml`, `deploy.sh`, `backup.sh`: copiati a ogni deploy da questa cartella;
- `.env`: configurazione di produzione, creata **una volta** da `provision.sh` con password casuali. Non è nel repository e non viene mai sovrascritta;
- `backups/`: dump notturni del database (ore 03:30, conservati 14 giorni).

## Secret e variabili su GitHub

| Nome | Tipo | Valore |
| --- | --- | --- |
| `VPS_HOST` | secret | IP del VPS |
| `VPS_USER` | secret | `root`, usato solo da *Provision server* |
| `VPS_SSH_KEY` | secret | chiave privata dedicata, senza passphrase (commento `github-actions-prugna`) |
| `VPS_PORT` | secret, facoltativo | porta SSH se diversa da 22 |
| `APP_DOMAIN` | variabile, facoltativa | dominio pubblico (default `dev.reimg.it`) |
| `APP_NAME` | variabile, facoltativa | nome dell'app mostrato nell'interfaccia e nelle email (default `Prugna`), aggiornato nel `.env` del server a ogni deploy |

Il deploy si collega come utente `deploy`, che può gestire solo Docker. `root` serve solo al workflow di preparazione.

## Workflow

| Workflow | Quando | Cosa fa |
| --- | --- | --- |
| **Provision server** | manuale (una volta) | Docker, firewall (SSH/80/443), swap 2 GB, aggiornamenti di sicurezza automatici, utente `deploy`, `.env`, backup notturno |
| **Deploy** | a ogni push su `main`, o manuale | test → immagine → smoke test → deploy |
| **Server check** | manuale | sola lettura: sistema, risorse, Docker, porte |

Al **primo deploy** viene creato il super amministratore `APP_ADMIN_EMAIL` (default `admin@<dominio>`). La password generata compare **una sola volta** nel log del passo *Deploy*: cambiala subito dal profilo.

## Operazioni comuni (via SSH sul VPS)

Entrare nella cartella dell'app come utente `deploy`:

```bash
sudo -iu deploy
```

```bash
cd /srv/boilerplate
```

Stato e log dei container:

```bash
docker compose ps
```

```bash
docker compose logs -f app worker
```

Comandi Artisan:

```bash
docker compose exec app php artisan about
```

Backup manuale:

```bash
./backup.sh
```

**Rollback** a un commit precedente (l'immagine di ogni commit resta su GHCR). Rilancia il workflow *Deploy* da GitHub su quel commit, oppure sul server:

```bash
APP_IMAGE=ghcr.io/saltydreamer/prugna:<sha-del-commit> ./deploy.sh
```

Il rollback non annulla le migrazioni del database: se una migrazione ha cambiato lo schema, ripristina anche un backup.

## Email

Finché non configuri un server SMTP, le email (reset password, verifica, notifiche) vengono solo scritte nel log (`MAIL_MAILER=log`). Per inviarle davvero, modifica `MAIL_*` in `/srv/boilerplate/.env` e riavvia:

```bash
docker compose up -d
```

## Server interno (prugna.reimg.it)

Prugna con i dati veri gira su un container LXC di Proxmox nella rete interna, dietro il reverse proxy che riceve le porte 80/443 dell'IP pubblico. Lo stack è lo stesso del VPS (stessi `compose.yaml`, `deploy.sh`, `backup.sh`), con due differenze:

- l'app serve **HTTP semplice sulla porta 80** del container (`APP_SERVER_NAME=:80`): l'HTTPS lo fa il reverse proxy;
- l'app si fida degli header `X-Forwarded-*` del proxy (`TRUSTED_PROXIES`, `*` o l'IP del proxy), così link, redirect e cookie restano in HTTPS.

Gli aggiornamenti arrivano da un **runner GitHub Actions** installato nel container (etichette `self-hosted,prugna`): a ogni merge su `main` il job *Deploy to the internal server* scarica l'immagine e riavvia lo stack, insieme al deploy del VPS. Nessuna porta va aperta verso l'ufficio per il deploy.

### 1. Container LXC su Proxmox

Debian 13 (la stabile attuale, supportata da Docker), **non privilegiato con `nesting=1` e `keyctl=1`** (servono a Docker), 2 vCPU, 4 GB di RAM, 30 GB di disco, IP statico nella LAN. Dalla shell del nodo Proxmox, scarica il modello Debian 13 (il nome esatto cambia con gli aggiornamenti: usa quello che compare nell'elenco):

```bash
pveam update
```

```bash
pveam available --section system | grep debian-13
```

```bash
pveam download local debian-13-standard_<versione>_amd64.tar.zst
```

Poi crea il container (adatta ID, nome del modello, storage, bridge e indirizzi):

```bash
pct create 120 local:vztmpl/debian-13-standard_<versione>_amd64.tar.zst --hostname prugna --unprivileged 1 --features nesting=1,keyctl=1 --cores 2 --memory 4096 --swap 1024 --rootfs local-lvm:30 --net0 name=eth0,bridge=vmbr0,ip=192.168.1.120/24,gw=192.168.1.1 --onboot 1
```

```bash
pct start 120
```

### 2. Preparazione (una volta)

Su GitHub: *Settings → Actions → Runners → New self-hosted runner*, copia il **token** di registrazione (vale un'ora). Poi copia `deploy/scripts/provision.sh` nel container (es. `pct push 120 provision.sh /root/provision.sh`) ed eseguilo come root:

```bash
INTERNAL=1 APP_DOMAIN=prugna.reimg.it TRUSTED_PROXIES=192.168.1.10 RUNNER_TOKEN=<token> bash /root/provision.sh
```

`TRUSTED_PROXIES` è l'IP LAN del reverse proxy (o `*`): metti il tuo, non quello dell'esempio (vedi il passo 3 per controllarlo). Lo script installa Docker, crea l'utente `deploy`, `/srv/boilerplate/.env` con password casuali, il backup notturno e il runner (servizio di sistema, utente `deploy`). Niente swap né firewall: in un LXC si gestiscono da Proxmox.

### 3. Reverse proxy e DNS

- Record DNS **A** `prugna.reimg.it` → IP pubblico dell'ufficio.
- Sul reverse proxy: `prugna.reimg.it` → `http://192.168.1.120:80` (certificato HTTPS sul proxy), con gli header `X-Forwarded-For`, `X-Forwarded-Proto` e `X-Forwarded-Host`; limite di upload almeno 25 MB (documenti fino a 20 MB).

Con **Apache** (`a2enmod proxy proxy_http headers`), nel `VirtualHost *:443` di `prugna.reimg.it`. `X-Forwarded-Proto` va impostato a mano: Apache non lo manda, e senza Prugna crede di essere in HTTP (pagina senza stili, errori «Mixed Content»).

```apache
ProxyPreserveHost On
ProxyTimeout 180
RequestHeader set X-Forwarded-Proto "https"
RequestHeader set X-Forwarded-Port "443"
ProxyPass / http://192.168.1.120/
ProxyPassReverse / http://192.168.1.120/
```

`TRUSTED_PROXIES` in `/srv/boilerplate/.env` dev'essere l'IP da cui il proxy raggiunge il container. Per vederlo, apri una pagina da `https://prugna.reimg.it` e poi, nel container:

```bash
cd /srv/boilerplate && docker compose exec app php artisan tinker --execute 'dump(DB::table("sessions")->orderByDesc("last_activity")->limit(3)->pluck("ip_address")->all());'
```

Se cambi `.env`, riavvia i servizi con `docker compose up -d --force-recreate app worker scheduler`.

### 4. Primo deploy

Su GitHub crea la variabile di repository **`INTERNAL_DEPLOY` = `true`** (*Settings → Secrets and variables → Actions → Variables*; facoltativa `INTERNAL_DOMAIN`, default `prugna.reimg.it`) e rilancia il workflow *Deploy*. Il job *Deploy to the internal server* avvia lo stack vuoto e verifica `http://localhost/up` e `https://prugna.reimg.it/up`.

### 5. Copia dei dati da dev.reimg.it

Workflow **Copy data to the internal server** (manuale, scrivi `COPIA`): fa un backup sul VPS, lo scarica nel container, copia l'`APP_KEY` (le password salvate nelle impostazioni — SMTP, AI, SDI — restano leggibili) e ripristina database e documenti con `restore.sh`. Si può ripetere finché dev.reimg.it ha i dati veri: ogni volta sostituisce i dati del server interno con quelli del VPS. Dopo il *Reset demo* il workflow si rifiuta di partire (il VPS ha `APP_DEMO=true`), così la demo non può mai finire sui dati veri.

Dopo la copia, su **prugna.reimg.it**:

1. accedi con i soliti utenti e controlla fatture, spese e documenti;
2. **Impostazioni → Fatturazione elettronica → «Collega»**: Openapi manda da quel momento esiti e fatture ricevute a prugna.reimg.it (finché non lo fai, i webhook puntano ancora a dev.reimg.it);
3. notifiche push e app installata: riattivale dal profilo e reinstalla l'app dal nuovo indirizzo (sono legate al dominio).

### Operazioni sul server interno

Come sul VPS (`sudo -iu deploy`, `cd /srv/boilerplate`, `docker compose …`). Ripristino di un backup:

```bash
./restore.sh backups/db-<data>.sql.gz backups/files-<data>.tar.gz
```

## Demo (dev.reimg.it)

Quando i dati veri sono sul server interno, dev.reimg.it diventa la **demo**: tre anni di lavoro di un sistemista a partita IVA in regime forfettario (Luca Bianchi, consulenza informatica e integrazione di sistemi), generati da `php artisan app:demo-seed` con le azioni dell'app: clienti e fornitori, interventi fatturati ogni mese, canoni, progetti dal preventivo alla fattura, monte ore, acquisti e spese, incassi, F24.

Workflow **Reset demo** (manuale, scrivi `AZZERA`): parte solo se `https://prugna.reimg.it/up` risponde, fa un backup, **cancella** database e documenti di dev.reimg.it, carica la demo, imposta `APP_DEMO=true` (avviso e accesso demo nella pagina di login, etichetta «Demo») e ricrea il super amministratore `APP_ADMIN_EMAIL` (password nel log, una sola volta). Si può rilanciare per riportare la demo allo stato iniziale.

Accesso demo: `demo@prugna.it` / `prugna-demo`. SDI, AI ed email restano spenti.
