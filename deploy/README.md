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
