# Boilerplate

Scheletro applicativo **Laravel 13 + Inertia 3 + Vue 3 + Vuetify 4 + MariaDB** con le funzionalità che servono in quasi ogni progetto:

- **Autenticazione** completa (Laravel Fortify): login, registrazione (disattivabile), recupero password, verifica email, autenticazione a due fattori (TOTP + codici di recupero), conferma password, rate limiting.
- **Gestione utenti**: elenco con ricerca, filtri per ruolo e stato, ordinamento e paginazione lato server; creazione (con password o con invio del link per sceglierla), modifica, disattivazione, eliminazione, invio del link di reset.
- **Gestione permessi**: ruoli e permessi con Spatie Permission. I permessi sono definiti in un enum PHP e i ruoli si gestiscono dall'interfaccia, con i permessi raggruppati per area.
- **Profilo utente**: dati personali, immagine del profilo, cambio password, 2FA, preferenze di notifica, sessioni attive (con disconnessione degli altri dispositivi), eliminazione account.
- **Centro notifiche**: campanella con contatore e anteprima, pagina dedicata con filtri (tutte/da leggere/lette), segna come letta/non letta, eliminazione, invio di **annunci** da parte degli amministratori (a tutti, per ruolo o a utenti specifici), copia via email facoltativa.
- **Anagrafiche**: organizzazioni (dati fiscali con verifica di partita IVA e codice fiscale, codice SDI, PEC), sedi e contatti con sede e referente principali, rubrica dei contatti con ricerca, archiviazione e ripristino.
- **Prodotti e servizi**: listino con codici automatici, categorie, unità di misura, costo e margine, aliquote IVA ed esenzioni con natura per la fattura elettronica, servizi a canone (mensile, trimestrale, semestrale, annuale), prezzi e sconti concordati per cliente con scadenza.
- **Preventivi**: numerazione continua, righe dal listino con prezzi concordati applicati in automatico, sconto per riga, sezioni, testi e voci facoltative, PDF, invio via email con link per accettare o rifiutare online (il cliente sceglie le voci facoltative), notifiche al responsabile, promemoria e scadenza automatici, visibilità per responsabile.
- **Ordini**: dal preventivo accettato con un clic (o inseriti a mano), conferma d'ordine in PDF, consegna prevista con avviso ritardo, stato di avanzamento, fatturato vs da fatturare per riga.
- **Fatture**: fattura unica, parziale (righe e quantità a scelta), acconto e saldo con detrazione automatica, note di credito; regime ordinario o forfettario, ritenuta d'acconto, cassa previdenziale, split payment e clienti PA (CIG/CUP), bollo virtuale automatico; numerazione FT-2026-0001 all'emissione con controlli preventivi; PDF di cortesia e XML FatturaPA validato sullo schema ufficiale, pronto per «Fatture e corrispettivi».
- **Acquisti**: anagrafiche cliente/fornitore, ordini ai fornitori (anche dall'ordine cliente, con i costi del listino) con PDF, invio email, consegna attesa e stati; ribaltamento dei costi al cliente con ricarico, direttamente in fattura, e margine stimato per ordine.
- **Impostazioni**: dati e logo dell'azienda, server SMTP con email di prova, testi dei preventivi, condizioni di pagamento (con predefinita per cliente).
- Interfaccia in italiano, tema chiaro/scuro/di sistema, layout responsive, pagine d'errore, toast e dialoghi di conferma globali.

## Stack

| Livello | Tecnologie |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13, Laravel Fortify, Spatie Laravel Permission, Ziggy |
| Frontend | Inertia.js 3, Vue 3 (`<script setup>`), Vuetify 4, Material Design Icons, Vite |
| Database | MariaDB 11 (SQLite in memoria per i test) |
| Dev | Laravel Sail (Docker: PHP, MariaDB, Mailpit), Laravel Boost, Pint, ESLint, Prettier, PHPUnit |
| CI | GitHub Actions: test su MariaDB (PHP 8.3 e 8.4), build degli asset, controllo dello stile |

## Avvio rapido

### Con Docker (Laravel Sail), consigliato

```bash
composer install
```

```bash
cp .env.example .env
```

```bash
./vendor/bin/sail up -d
```

```bash
./vendor/bin/sail artisan key:generate
```

```bash
./vendor/bin/sail artisan storage:link
```

```bash
./vendor/bin/sail artisan migrate --seed
```

```bash
./vendor/bin/sail npm install
```

```bash
./vendor/bin/sail composer dev
```

- Applicazione: http://localhost
- Mailpit (email in uscita): http://localhost:8025

### Senza Docker

Servono PHP 8.3+, Composer, Node 22+ e un server MariaDB. In `.env` imposta `DB_HOST=127.0.0.1` e le credenziali del tuo database, poi:

```bash
composer setup
```

```bash
composer dev
```

`composer dev` avvia insieme server web, **worker delle code** (necessario per le notifiche), log e Vite.

### Utenti di esempio

Il seeder crea questi account (password: `password`):

| Email | Ruolo |
| --- | --- |
| superadmin@example.com | Super amministratore (tutti i permessi) |
| admin@example.com | Amministratore |
| user@example.com | Utente |

In ambiente `local` vengono creati anche 25 utenti fittizi.

## Struttura

```
app/
├── Actions/Fortify/            # Registrazione, aggiornamento profilo e password (Fortify)
├── Actions/Permissions/        # SyncPermissions: allinea il DB agli enum
├── Enums/Permission.php        # ← unica fonte dei permessi
├── Enums/Role.php              # ruoli di sistema (super-admin, admin, user)
├── Http/Controllers/Admin/     # Utenti, ruoli, annunci
├── Http/Controllers/Profile/   # Profilo, avatar, sessioni
├── Http/Middleware/            # HandleInertiaRequests (prop condivise), EnsureUserIsActive
├── Notifications/              # AppNotification (base) + notifiche concrete
└── Policies/                   # UserPolicy, RolePolicy
resources/js/
├── app.js                      # bootstrap Inertia + scelta automatica del layout
├── plugins/vuetify.js          # tema, lingua, default dei componenti
├── layouts/                    # AppLayout (drawer, app bar, campanella), AuthLayout
├── components/                 # NotificationBell, UserAvatar, PageHeader, ToastQueue, ...
├── composables/                # usePermissions, useConfirm, useToast, useInertiaLink
└── pages/                      # auth/, admin/, profile/, notifications/, Dashboard, Error
```

## Come si estende

### Aggiungere un permesso

1. Aggiungi un case in `app/Enums/Permission.php` e completa `label()` e `group()`.
2. Esegui la sincronizzazione (va lanciata anche a ogni deploy):

   ```bash
   php artisan permissions:sync
   ```

   Con `--prune` vengono eliminati i permessi non più presenti nell'enum.
3. Lato server proteggi rotte e azioni con `can:` / policy / `$this->authorize()`; lato client nascondi la UI con `usePermissions().can('nome.permesso')`.

Il ruolo `super-admin` ottiene automaticamente ogni permesso (`Gate::before` in `AppServiceProvider`). Le regole strutturali delle policy restano comunque valide: nessuno può eliminare sé stesso, i ruoli di sistema non si possono eliminare, deve sempre esistere almeno un super amministratore.

### Creare una notifica

```php
namespace App\Notifications;

class InvoicePaidNotification extends AppNotification
{
    public function __construct(public Invoice $invoice) {}

    public function title(): string { return __('Fattura pagata'); }
    public function message(): string { return __('La fattura :n è stata saldata.', ['n' => $this->invoice->number]); }
    public function icon(): string { return 'mdi-receipt-text-check-outline'; }
    public function color(): string { return 'success'; }
    public function actionUrl(): ?string { return route('invoices.show', $this->invoice); }
}
```

```php
$user->notify(new InvoicePaidNotification($invoice));
```

La notifica compare nel centro notifiche e, se l'utente ha attivato le email, arriva anche per posta. Le notifiche sono **in coda**: in produzione serve un worker (`php artisan queue:work`, con Supervisor o Laravel Horizon).

Il contatore della campanella si aggiorna ogni 60 secondi con un polling Inertia (`usePoll`). Per il tempo reale si può aggiungere Laravel Reverb + Echo e il canale `broadcast` in `AppNotification::via()`.

### Aggiungere una pagina

1. Crea il componente in `resources/js/pages/...` (es. `pages/invoices/Index.vue`). Il layout applicativo viene applicato in automatico.
2. Registra la rotta in `routes/web.php` e restituisci `Inertia::render('invoices/Index', [...])`.
3. Aggiungi la voce di menu nell'array `navigation` di `resources/js/layouts/AppLayout.vue` (con `permission` facoltativo).

### Messaggi di esito (toast)

```php
Inertia::flash('toast', ['type' => 'success', 'message' => __('Salvato.')]);

return back();
```

Lato client si possono mostrare anche direttamente con `useToast().success('...')`.

## Configurazione utile

| Variabile | Effetto |
| --- | --- |
| `FORTIFY_REGISTRATION=false` | Disattiva la registrazione pubblica (gli utenti li crea solo l'amministratore) |
| `SESSION_DRIVER=database` | Necessario per l'elenco delle sessioni attive nel profilo |
| `QUEUE_CONNECTION` | Le notifiche sono in coda: con `sync` vengono inviate subito (sconsigliato in produzione) |
| `APP_LOCALE=it` | Lingua dei messaggi di validazione (`lang/it`) |

In produzione la password richiede almeno 10 caratteri con maiuscole, minuscole e numeri, e non deve comparire in data breach noti (`AppServiceProvider::configureDefaults`).

## Qualità del codice

```bash
php artisan test
```

```bash
vendor/bin/pint
```

```bash
npm run lint
```

```bash
npm run format
```

I test di feature (`tests/Feature`) coprono autenticazione, registrazione, gestione utenti e ruoli, centro notifiche, annunci e profilo. In CI vengono eseguiti su MariaDB.

## Deploy

Il deploy automatico su VPS (Docker + GitHub Actions, HTTPS automatico) è descritto in [deploy/README.md](deploy/README.md).

### Deploy manuale (promemoria)

```bash
composer install --no-dev --optimize-autoloader
```

```bash
npm ci && npm run build
```

```bash
php artisan migrate --force
```

```bash
php artisan permissions:sync
```

```bash
php artisan optimize
```

Ricordati anche di creare il link allo storage (`php artisan storage:link`), di avviare un worker delle code e lo scheduler.
