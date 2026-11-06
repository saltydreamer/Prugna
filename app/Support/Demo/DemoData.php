<?php

namespace App\Support\Demo;

use App\Actions\Accounting\RecordInvoiceCollection;
use App\Actions\Accounting\SyncExpensePayment;
use App\Actions\Documents\SyncDocumentLines;
use App\Actions\Expenses\SaveExpense;
use App\Actions\Interventions\BillInterventions;
use App\Actions\Interventions\SaveIntervention;
use App\Actions\Invoices\BuildInvoice;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Invoices\SaveInvoice;
use App\Actions\Orders\SaveOrder;
use App\Actions\Purchases\SavePurchaseOrder;
use App\Actions\Quotes\CreateHourPackages;
use App\Actions\Quotes\SaveQuote;
use App\Actions\Subscriptions\GenerateSubscriptionInvoices;
use App\Enums\BillingPeriod;
use App\Enums\InterventionMode;
use App\Enums\InvoiceType;
use App\Enums\LocationType;
use App\Enums\OrderStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Models\AccountingCategory;
use App\Models\AccountMovement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Intervention;
use App\Models\InterventionCategory;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VatRate;
use App\Support\DocumentNumber;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Demonstration data (dev.reimg.it): Luca Bianchi, a freelance system administrator in the flat-rate
 * regime (IT consulting and systems integration), with his last three years of work: customers and
 * suppliers, services and hardware, interventions invoiced every month, service contracts, projects from
 * quote to invoice, hour contracts, purchases and expenses, collections and F24 payments.
 *
 * Everything goes through the application's own actions, day by day with the clock set to that day,
 * so numbering, totals, stamp duty, hour contracts and payments are what real use would produce.
 * No Faker: it is not installed in production. A fixed seed makes every reset the same.
 */
class DemoData
{
    public const EMAIL = 'demo@prugna.it';

    public const PASSWORD = 'prugna-demo';

    private const SEED = 20231106;

    private User $user;

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, Organization> */
    private array $customers = [];

    /** @var array<string, Organization> */
    private array $suppliers = [];

    /** @var array<string, array{activity: int, onsite: float, payer: string}> */
    private array $profiles = [];

    /** @var array<string, int> */
    private array $documentNumbers = [];

    /** @var array<string, int> legacy key => accounting category id */
    private array $categories = [];

    /** @var array<string, list<Closure(CarbonImmutable): void>> */
    private array $agenda = [];

    private FinancialAccount $bank;

    private FinancialAccount $personal;

    private InterventionCategory $support;

    private InterventionCategory $consulting;

    private int $vat22;

    private int $vatFree;

    private int $vatExempt;

    private CarbonImmutable $start;

    private CarbonImmutable $end;

    private CarbonImmutable $realNow;

    /** @var array<string, int> */
    private array $stats = ['customers' => 0, 'suppliers' => 0, 'interventions' => 0, 'quotes' => 0, 'orders' => 0, 'invoices' => 0, 'expenses' => 0, 'collections' => 0];

    public function __construct(
        private Settings $settings,
        private SaveQuote $saveQuote,
        private SaveOrder $saveOrder,
        private CreateHourPackages $hourPackages,
        private BuildInvoice $buildInvoice,
        private SaveInvoice $saveInvoice,
        private IssueInvoice $issueInvoice,
        private RecordInvoiceCollection $recordCollection,
        private SaveIntervention $saveIntervention,
        private BillInterventions $billInterventions,
        private GenerateSubscriptionInvoices $subscriptionInvoices,
        private SyncDocumentLines $syncLines,
        private SaveExpense $saveExpense,
        private SyncExpensePayment $syncExpensePayment,
        private SavePurchaseOrder $savePurchaseOrder,
    ) {}

    /**
     * Fills an empty database with three years of work up to today.
     *
     * @param  (Closure(CarbonImmutable): void)|null  $progress  Called at the start of every month.
     * @param  int  $months  How far back the work starts (three years; fewer in the tests).
     * @return array<string, int> What was created.
     */
    public function run(?Closure $progress = null, int $months = 36): array
    {
        mt_srand(self::SEED);
        // Contract alerts and other notifications would fill the demo inbox and the queue.
        Notification::fake();

        $this->realNow = CarbonImmutable::now();
        $this->end = CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
        $this->start = $this->end->subMonthsNoOverflow($months)->startOfMonth();

        try {
            $this->at($this->start);
            $this->company();
            $this->demoUser();
            $this->catalog();
            $this->registry();
            $this->accounts();
            $this->plan();

            for ($day = $this->start; $day->lte($this->end); $day = $day->addDay()) {
                $this->at($day);

                if ($progress && $day->day === 1) {
                    $progress($day);
                }

                $this->day($day);
            }
        } finally {
            Carbon::setTestNow();
        }

        return $this->stats;
    }

    // --- Setup -----------------------------------------------------------------------------

    private function company(): void
    {
        $this->vat22 = (int) VatRate::query()->where('rate', 22)->whereNull('nature')->value('id');
        $this->vatFree = (int) VatRate::query()->where('nature', 'N2.2')->value('id');
        $this->vatExempt = (int) VatRate::query()->where('nature', 'N4')->value('id');
        $this->categories = AccountingCategory::query()->whereNotNull('legacy_key')->pluck('id', 'legacy_key')->all();

        $this->settings->set([
            'company.name' => 'Luca Bianchi – Sistemi e consulenza IT',
            'company.vat_number' => self::partitaIva('0451230023'),
            'company.tax_code' => self::taxCode('BNCLCU85M14L781'),
            'company.address' => 'Via Cesare Battisti 18',
            'company.postal_code' => '37122',
            'company.city' => 'Verona',
            'company.province' => 'VR',
            'company.country' => 'IT',
            'company.email' => 'info@lucabianchi-it.example',
            'company.pec' => 'lucabianchi@pec-demo.example',
            'company.phone' => '+39 045 800 1234',
            'company.website' => 'www.lucabianchi-it.example',
            'company.iban' => 'IT60X0542811101000000123456',
            'fiscal.regime' => 'RF19',
            'fiscal.stamp_duty_enabled' => true,
            'fiscal.stamp_duty_charge_customer' => true,
            'fiscal.withholding_enabled' => false,
            'fiscal.invoice_notes' => "Operazione effettuata ai sensi dell'art. 1, commi 54-89, L. 190/2014 – regime forfettario: non soggetta a IVA né a ritenuta d'acconto.",
            'accounting.coefficient' => 67,
            'accounting.tax_rate' => 5,
            'accounting.inps_regime' => 'separata',
            'accounting.inps_rate' => 26.07,
            'interventions.rounding_minutes' => 30,
            'quotes.validity_days' => 30,
            'sdi.enabled' => false,
            'sdi.company_enabled' => false,
            'ai.enabled' => false,
            'mail.enabled' => false,
        ]);
    }

    private function demoUser(): void
    {
        $this->user = User::query()->forceCreate([
            'name' => 'Luca Bianchi',
            'email' => self::EMAIL,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'email_notifications' => false,
            'email_verified_at' => now(),
        ]);
        $this->user->assignRole(Role::Admin->value);
    }

    private function catalog(): void
    {
        $support = ProductCategory::query()->create(['name' => 'Assistenza', 'color' => 'primary']);
        $fees = ProductCategory::query()->create(['name' => 'Canoni', 'color' => 'teal']);
        $projects = ProductCategory::query()->create(['name' => 'Progetti', 'color' => 'indigo']);
        $hardware = ProductCategory::query()->create(['name' => 'Hardware e licenze', 'color' => 'orange']);

        $services = [
            'remote' => ['S00001', 'Assistenza sistemistica da remoto', 'h', 45, $support],
            'onsite' => ['S00002', 'Assistenza sistemistica on site', 'h', 55, $support],
            'callout' => ['S00003', 'Diritto di uscita', 'forfait', 25, $support],
            'km' => ['S00004', 'Rimborso chilometrico', 'km', 0.45, $support],
            'travel' => ['S00005', 'Tempo di viaggio', 'h', 30, $support],
            'consulting_remote' => ['S00006', 'Consulenza e progettazione da remoto', 'h', 60, $projects],
            'consulting_onsite' => ['S00007', 'Consulenza e progettazione on site', 'h', 65, $projects],
            'package' => ['S00008', 'Monte ore assistenza prepagato', 'h', 42, $support],
            'backup' => ['S00009', 'Backup in cloud gestito', 'mese', 25, $fees],
            'monitoring' => ['S00010', 'Monitoraggio server e rete', 'mese', 35, $fees],
            'hosting' => ['S00011', 'Dominio, hosting e caselle di posta', 'anno', 180, $fees],
            'firewall_setup' => ['S00012', 'Configurazione firewall e VPN', 'forfait', 450, $projects],
            'pc_setup' => ['S00013', 'Installazione e configurazione PC', 'forfait', 90, $projects],
            'm365' => ['S00014', 'Migrazione a Microsoft 365', 'forfait', 600, $projects],
        ];

        foreach ($services as $key => [$code, $name, $unit, $price, $category]) {
            $this->products[$key] = Product::query()->create([
                'type' => ProductType::Service, 'code' => $code, 'name' => $name, 'category_id' => $category->id, 'unit' => $unit,
                'price' => $price, 'vat_rate_id' => $this->vat22, 'is_active' => true,
                'billing_period' => match ($key) {
                    'backup', 'monitoring' => BillingPeriod::Monthly,
                    'hosting' => BillingPeriod::Annual,
                    default => null,
                },
                'hour_package' => $key === 'package',
                'package_months' => $key === 'package' ? 12 : null,
            ]);
        }

        $goods = [
            'switch' => ['P00001', 'Switch gestito 24 porte PoE', 320, 238],
            'nas' => ['P00002', 'NAS 4 bay con dischi da 4 TB', 690, 520],
            'firewall' => ['P00003', 'Firewall UTM per piccoli uffici', 780, 585],
            'ap' => ['P00004', 'Access point Wi-Fi 6', 160, 112],
            'ups' => ['P00005', 'Gruppo di continuità 1500 VA', 290, 205],
            'pc' => ['P00006', 'PC desktop per ufficio', 850, 680],
            'server' => ['P00007', 'Server tower con Windows Server', 2400, 1890],
            'antivirus' => ['P00008', 'Licenza antivirus endpoint (1 anno)', 35, 21],
            'ssd' => ['P00009', 'Disco SSD 1 TB', 110, 72],
            'cable' => ['P00010', 'Cavo di rete Cat. 6 (patch)', 6, 2.5],
        ];

        foreach ($goods as $key => [$code, $name, $price, $cost]) {
            $this->products[$key] = Product::query()->create([
                'type' => ProductType::Product, 'code' => $code, 'name' => $name, 'category_id' => $hardware->id, 'unit' => 'pz',
                'price' => $price, 'cost' => $cost, 'vat_rate_id' => $this->vat22, 'is_active' => true,
            ]);
        }

        $this->support = InterventionCategory::query()->create(['name' => 'Assistenza sistemistica', 'remote_product_id' => $this->products['remote']->id, 'onsite_product_id' => $this->products['onsite']->id, 'is_active' => true, 'sort' => 1]);
        $this->consulting = InterventionCategory::query()->create(['name' => 'Consulenza e progettazione', 'remote_product_id' => $this->products['consulting_remote']->id, 'onsite_product_id' => $this->products['consulting_onsite']->id, 'is_active' => true, 'sort' => 2]);

        $this->settings->set([
            'interventions.callout_product_id' => $this->products['callout']->id,
            'interventions.km_product_id' => $this->products['km']->id,
            'interventions.travel_product_id' => $this->products['travel']->id,
        ]);
    }

    private function registry(): void
    {
        $terms = PaymentTerm::query()->pluck('id', 'name');
        $transfer30 = $terms['Bonifico bancario 30 gg d.f.'] ?? PaymentTerm::default()?->id;
        $transfer30eom = $terms['Bonifico bancario 30 gg d.f.f.m.'] ?? $transfer30;
        $onSight = $terms['Bonifico bancario a vista'] ?? $transfer30;
        $riba60 = $terms['Ri.Ba. 60 gg d.f.f.m.'] ?? $transfer30;

        // key => [name, type, city, cap, street, contact, role, activity, onsite share, payer, payment term]
        $customers = [
            'rossi' => ['Studio Rossi & Associati – Commercialisti', OrganizationType::Company, 'Verona', '37121', 'Corso Porta Nuova 41', ['Elena', 'Rossi', 'Socia'], 10, 0.2, 'fast', $transfer30],
            'bortolotti' => ['Officine Meccaniche Bortolotti Srl', OrganizationType::Company, 'San Giovanni Lupatoto', '37057', 'Via dell\'Industria 22', ['Paolo', 'Bortolotti', 'Amministratore'], 9, 0.45, 'normal', $riba60],
            'logistica' => ['Logistica Adige Srl', OrganizationType::Company, 'Verona', '37135', 'Via Germania 9', ['Sara', 'Pozzani', 'Responsabile amministrativa'], 8, 0.3, 'normal', $transfer30eom],
            'ferri' => ['Studio Dentistico Dott.ssa Laura Ferri', OrganizationType::Freelancer, 'Villafranca di Verona', '37069', 'Via Pace 15', ['Laura', 'Ferri', 'Titolare'], 5, 0.35, 'fast', $onSight],
            'hotel' => ['Hotel Lago Verde Srl', OrganizationType::Company, 'Bardolino', '37011', 'Lungolago Lenotti 3', ['Marco', 'Benedetti', 'Direttore'], 4, 0.5, 'slow', $transfer30eom],
            'cantina' => ['Cantina Valpolicella Riva Soc. Agr.', OrganizationType::Company, 'Negrar di Valpolicella', '37024', 'Via Mazzini 60', ['Giulia', 'Riva', 'Titolare'], 3, 0.4, 'normal', $transfer30],
            'desantis' => ['Avv. Marco De Santis', OrganizationType::Freelancer, 'Verona', '37122', 'Stradone Maffei 7', ['Marco', 'De Santis', 'Avvocato'], 3, 0.3, 'fast', $onSight],
            'colombo' => ['Autofficina Colombo Srl', OrganizationType::Company, 'Bussolengo', '37012', 'Via Verona 118', ['Franco', 'Colombo', 'Titolare'], 3, 0.6, 'bad', $transfer30],
            'farmacia' => ['Farmacia Centrale Dr. Gallo', OrganizationType::Company, 'Pescantina', '37026', 'Piazza San Rocco 2', ['Andrea', 'Gallo', 'Farmacista titolare'], 3, 0.4, 'normal', $transfer30],
            'casablu' => ['Agenzia Immobiliare Casa Blu Srl', OrganizationType::Company, 'Verona', '37129', 'Via XX Settembre 55', ['Chiara', 'Lonardi', 'Segreteria'], 2, 0.25, 'normal', $transfer30],
            'martini' => ['Falegnameria Martini Snc', OrganizationType::Company, 'Sommacampagna', '37066', 'Via Osteria Grande 31', ['Luigi', 'Martini', 'Socio'], 2, 0.6, 'slow', $riba60],
            'visconti' => ['Ottica Visconti Snc', OrganizationType::Company, 'Verona', '37121', 'Via Mazzini 12', ['Anna', 'Visconti', 'Titolare'], 2, 0.4, 'normal', $transfer30],
            'zeni' => ['Panificio Fratelli Zeni Snc', OrganizationType::Company, 'Grezzana', '37023', 'Via Roma 4', ['Davide', 'Zeni', 'Socio'], 2, 0.5, 'normal', $onSight],
            'arabesque' => ['Scuola di Danza Arabesque ASD', OrganizationType::NonProfit, 'Verona', '37138', 'Via Fincato 90', ['Monica', 'Tosi', 'Presidente'], 1, 0.5, 'slow', $onSight],
        ];

        $index = 0;

        foreach ($customers as $key => [$name, $type, $city, $cap, $street, $contact, $activity, $onsite, $payer, $term]) {
            $index++;
            $this->customers[$key] = $this->organization(OrganizationRole::Customer, $name, $type, $city, $cap, $street, $contact, $term, $index);
            $this->profiles[$key] = ['activity' => $activity, 'onsite' => $onsite, 'payer' => $payer];
        }

        $suppliers = [
            'esprinet' => ['Distribuzione Informatica Nord Spa', 'Vimercate', '20871', 'Via Energy Park 20', ['Ufficio', 'Ordini', 'Customer service'], 'MI'],
            'amazon' => ['Marketplace Online Italia Srl', 'Milano', '20124', 'Viale Monte Grappa 3', ['Servizio', 'Clienti', ''], 'MI'],
            'aruba' => ['Hosting Domini Italia Spa', 'Bergamo', '24121', 'Via San Clemente 53', ['Supporto', 'Tecnico', ''], 'BG'],
            'tim' => ['Telecomunicazioni Nazionali Spa', 'Milano', '20123', 'Via Gaetano Negri 1', ['Servizio', 'Clienti', ''], 'MI'],
            'microsoft' => ['Software Cloud Europe Srl', 'Milano', '20145', 'Viale Pasubio 21', ['Licensing', 'Team', ''], 'MI'],
            'coworking' => ['Verona Hub Coworking Srl', 'Verona', '37138', 'Via Albere 23', ['Reception', 'Hub', 'Amministrazione'], 'VR'],
            'fuel' => ['Stazione di Servizio Lupatoto Snc', 'San Giovanni Lupatoto', '37057', 'Via Garibaldi 101', ['Gestore', 'Stazione', ''], 'VR'],
            'accountant' => ['Studio Contabile Marchi', 'Verona', '37122', 'Via Leoncino 5', ['Roberta', 'Marchi', 'Commercialista'], 'VR'],
            'insurance' => ['Assicurazioni Professionali Spa', 'Trieste', '34132', 'Piazza Duca degli Abruzzi 2', ['Agenzia', 'Verona', ''], 'TS'],
        ];

        foreach ($suppliers as $key => [$name, $city, $cap, $street, $contact, $province]) {
            $index++;
            $this->suppliers[$key] = $this->organization(OrganizationRole::Supplier, $name, OrganizationType::Company, $city, $cap, $street, $contact, $transfer30, $index, $province);
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $contact
     */
    private function organization(OrganizationRole $role, string $name, OrganizationType $type, string $city, string $postalCode, string $street, array $contact, ?int $paymentTermId, int $index, string $province = 'VR'): Organization
    {
        $words = explode(' ', $name);
        $domain = strtolower((string) preg_replace('/[^a-z]+/i', '', $words[0].($words[1] ?? ''))).'.example';

        $organization = new Organization([
            'name' => $name,
            'type' => $type,
            'role' => $role,
            // Seven digits of the taxpayer, then the office code of the province (Verona 023).
            'vat_number' => $type === OrganizationType::NonProfit ? null : self::partitaIva(sprintf('%07d023', 1200000 + $index * 7919 % 800000)),
            'tax_code' => $type === OrganizationType::NonProfit ? self::partitaIva(sprintf('9%06d023', 300000 + $index)) : null,
            'sdi_code' => $role === OrganizationRole::Customer ? strtoupper(substr(md5($name), 0, 7)) : null,
            'email' => 'amministrazione@'.$domain,
            'pec' => $role === OrganizationRole::Customer ? 'pec@pec.'.$domain : null,
            'phone' => sprintf('+39 045 %03d %04d', 100 + $index * 37 % 900, 1000 + $index * 613 % 9000),
            'payment_term_id' => $paymentTermId,
        ]);
        $organization->created_by = $this->user->id ?? null;
        $organization->save();

        $location = $organization->locations()->create([
            'name' => __('Sede legale'), 'type' => LocationType::Registered, 'address' => $street, 'postal_code' => $postalCode,
            'city' => $city, 'province' => $province, 'country' => 'IT',
        ]);

        $organization->contacts()->create([
            'location_id' => $location->id,
            'first_name' => $contact[0],
            'last_name' => $contact[1],
            'job_title' => $contact[2] ?: null,
            'email' => strtolower($contact[0]).'@'.$domain,
            'phone' => $organization->phone,
            'is_primary' => true,
        ]);

        $this->stats[$role === OrganizationRole::Customer ? 'customers' : 'suppliers']++;

        return $organization;
    }

    private function accounts(): void
    {
        $this->bank = FinancialAccount::query()->create([
            'name' => 'Conto corrente Banca Demo', 'type' => 'bank', 'iban' => 'IT60X0542811101000000123456',
            'opening_balance' => 6500, 'opening_date' => $this->start->toDateString(), 'is_active' => true, 'sort' => 1,
        ]);
        $this->personal = FinancialAccount::query()->create([
            'name' => 'Conto personale', 'type' => 'bank', 'opening_balance' => 0,
            'opening_date' => $this->start->toDateString(), 'is_active' => true, 'sort' => 2,
        ]);
    }

    // --- The plan of the three years ---------------------------------------------------------

    private function plan(): void
    {
        $month = fn (int $offset, int $day = 10) => $this->workingDay($this->start->addMonthsNoOverflow($offset)->setDay(min($day, 28)));

        // Service contracts: [customer, service, months from the start, quantity, price].
        foreach ([
            ['rossi', 'backup', 1, 1, 25], ['rossi', 'monitoring', 1, 1, 35], ['ferri', 'backup', 3, 1, 25],
            ['logistica', 'monitoring', 2, 1, 35], ['logistica', 'backup', 6, 2, 25], ['bortolotti', 'monitoring', 8, 1, 35],
            ['martini', 'hosting', 0, 1, 180], ['casablu', 'hosting', 2, 1, 180], ['visconti', 'hosting', 4, 1, 160],
            ['zeni', 'hosting', 7, 1, 180], ['desantis', 'hosting', 9, 1, 180], ['hotel', 'backup', 14, 1, 25],
        ] as [$customer, $service, $offset, $quantity, $price]) {
            $this->schedule($this->start->addMonthsNoOverflow($offset), fn (CarbonImmutable $day) => $this->subscription($customer, $service, $day, $quantity, $price));
        }

        // Projects: [months from the start, customer, title, lines [product, quantity, price?], outcome, deposit %].
        $projects = [
            [2, 'hotel', 'Rete Wi-Fi per camere e area ospiti', [['ap', 6], ['switch', 1], ['firewall', 1], ['firewall_setup', 1], ['onsite', 16]], 'accepted', 30],
            [4, 'cantina', 'Firewall e VPN per gli agenti', [['firewall', 1], ['firewall_setup', 1], ['consulting_remote', 6]], 'accepted', 0],
            [5, 'desantis', 'Migrazione a Microsoft 365 e nuove postazioni', [['m365', 1], ['pc', 2], ['pc_setup', 2], ['antivirus', 2]], 'accepted', 30],
            [7, 'bortolotti', 'Monte ore assistenza 20 h', [['package', 20]], 'accepted', 0],
            [9, 'farmacia', 'Rinnovo postazioni banco', [['pc', 3], ['pc_setup', 3], ['antivirus', 3]], 'rejected', 0],
            [11, 'ferri', 'Monte ore assistenza 10 h', [['package', 10]], 'accepted', 0],
            [13, 'logistica', 'NAS e piano di backup per il magazzino', [['nas', 1], ['ssd', 2], ['consulting_onsite', 8]], 'accepted', 30],
            [15, 'arabesque', 'Wi-Fi per la sala prove', [['ap', 2], ['onsite', 3]], 'expired', 0],
            [17, 'bortolotti', 'Monte ore assistenza 20 h', [['package', 20]], 'accepted', 0],
            [19, 'rossi', 'Nuovo server di studio e migrazione dati', [['server', 1], ['ups', 1], ['consulting_onsite', 14]], 'accepted', 40],
            [22, 'hotel', 'Estensione Wi-Fi all\'area piscina', [['ap', 3], ['cable', 10], ['onsite', 6]], 'accepted', 0],
            [23, 'ferri', 'Monte ore assistenza 10 h', [['package', 10]], 'accepted', 0],
            [25, 'colombo', 'Armadio rack, UPS e switch', [['ups', 2], ['switch', 1], ['onsite', 5]], 'accepted', 0],
            [28, 'bortolotti', 'Monte ore assistenza 20 h', [['package', 20]], 'accepted', 0],
            [30, 'visconti', 'Nuovo sito vetrina e posta', [['consulting_remote', 12], ['hosting', 1]], 'rejected', 0],
            [33, 'cantina', 'Sostituzione firewall e aggiornamento VPN', [['firewall', 1], ['firewall_setup', 1]], 'accepted', 0],
        ];

        foreach ($projects as [$offset, $customer, $title, $lines, $outcome, $deposit]) {
            $this->schedule($month($offset, mt_rand(3, 24)), fn (CarbonImmutable $day) => $this->project($day, $customer, $title, $lines, $outcome, $deposit));
        }

        // Still open in the last weeks: one sent, one draft.
        $this->schedule($this->workingDay($this->end->subDays(12)), fn (CarbonImmutable $day) => $this->project($day, 'logistica', 'Segmentazione della rete in VLAN', [['switch', 2], ['consulting_onsite', 10]], 'sent', 0));
        $this->schedule($this->workingDay($this->end->subDays(3)), fn (CarbonImmutable $day) => $this->project($day, 'zeni', 'Nuovo PC cassa e stampante fiscale in rete', [['pc', 1], ['pc_setup', 1], ['onsite', 2]], 'draft', 0));

        // A double invoice cancelled with a credit note.
        $this->schedule($month(18, 4), function (CarbonImmutable $day): void {
            $invoice = Invoice::query()->where('organization_id', $this->customers['casablu']->id)->where('type', InvoiceType::Invoice->value)
                ->where('status', 'issued')->whereDate('issue_date', '>=', $day->subDays(20)->toDateString())->latest('id')->first()
                ?? Invoice::query()->where('type', InvoiceType::Invoice->value)->where('status', 'issued')->latest('id')->first();

            if ($invoice) {
                $this->issue([...$this->buildInvoice->creditNote($invoice), 'notes' => __('Storno della fattura :number emessa per errore.', ['number' => $invoice->number])]);
            }
        });
    }

    /**
     * One day of work: the monthly invoices and costs on the first working day, then what was planned,
     * the interventions and the small expenses.
     */
    private function day(CarbonImmutable $day): void
    {
        if ($this->isFirstWorkingDay($day)) {
            $this->monthlyInvoices($day);
            $this->monthlyCosts($day);
        }

        foreach ($this->agenda[$day->toDateString()] ?? [] as $action) {
            $action($day);
        }

        unset($this->agenda[$day->toDateString()]);

        if ($day->isWeekday() && ! $day->isSameDay($this->end)) {
            $this->interventions($day);
            $this->smallExpenses($day);
        }

        if (($day->month === 6 || $day->month === 11) && $day->isSameDay($this->workingDay($day->setDay(28)))) {
            $this->taxes($day);
        }
    }

    // --- Invoices ----------------------------------------------------------------------------

    /**
     * The interventions of last month, one invoice per customer, and the fees of the service contracts.
     */
    private function monthlyInvoices(CarbonImmutable $day): void
    {
        $monthStart = CarbonImmutable::parse($day->startOfMonth()->toDateString(), config('app.display_timezone'))->utc();

        foreach ($this->customers as $customer) {
            $interventions = $this->billInterventions->available($customer->id)->filter(fn (Intervention $intervention) => $intervention->started_at->lt($monthStart))->values();

            if ($interventions->isEmpty()) {
                continue;
            }

            try {
                $this->issue($this->buildInvoice->fromInterventions($customer->id, null, $interventions, true));
            } catch (ValidationException) {
                continue;
            }
        }

        foreach ($this->subscriptionInvoices->handle($day, null, $this->user) as $draft) {
            $this->issueDraft($draft);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function issue(array $data): Invoice
    {
        return $this->issueDraft($this->saveInvoice->handle(new Invoice, [...$data, 'owner_id' => $this->user->id]));
    }

    private function issueDraft(Invoice $draft): Invoice
    {
        $invoice = $this->issueInvoice->handle($draft);
        $this->stats['invoices']++;

        if ($invoice->type !== InvoiceType::CreditNote) {
            $this->schedulePayment($invoice);
        }

        return $invoice;
    }

    /**
     * Customers pay as they usually do: some at once, some late, one sometimes not at all.
     */
    private function schedulePayment(Invoice $invoice): void
    {
        $payer = collect($this->customers)->search(fn (Organization $customer) => $customer->id === $invoice->organization_id);
        $profile = $this->profiles[$payer]['payer'] ?? 'normal';

        if ($profile === 'bad' && $this->chance(0.15)) {
            return;
        }

        $delay = match ($profile) {
            'fast' => mt_rand(3, 18),
            'slow' => mt_rand(35, 80),
            'bad' => mt_rand(60, 150),
            default => mt_rand(15, 45),
        };
        $id = $invoice->id;

        $this->schedule(CarbonImmutable::parse($invoice->issue_date->toDateString())->addDays($delay), function (CarbonImmutable $day) use ($id): void {
            $invoice = Invoice::query()->find($id);

            if ($invoice && $invoice->outstanding() > 0) {
                $this->recordCollection->handle($invoice, $this->bank->id, $day->toDateString(), $invoice->outstanding(), $this->user->id);
                $this->stats['collections']++;
            }
        });
    }

    // --- Service contracts, projects ---------------------------------------------------------

    private function subscription(string $customerKey, string $serviceKey, CarbonImmutable $day, int $quantity, float $price): void
    {
        $customer = $this->customers[$customerKey];
        $service = $this->products[$serviceKey];

        $subscription = new Subscription([
            'organization_id' => $customer->id,
            'title' => $service->name,
            'billing_period' => $service->billing_period,
            'billing_mode' => Subscription::MODE_ADVANCE,
            'starts_on' => $day->toDateString(),
            'payment_term_id' => $customer->payment_term_id,
            'owner_id' => $this->user->id,
            'is_active' => true,
        ]);
        $subscription->forceFill(DocumentNumber::next(Subscription::class, 'CS', $day->year))->save();
        $this->syncLines->handle($subscription->lines(), [[
            'type' => 'item', 'product_id' => $service->id, 'code' => $service->code, 'description' => $service->name,
            'unit' => $service->unit, 'quantity' => $quantity, 'unit_price' => $price, 'discount_percent' => 0, 'vat_rate_id' => $this->vatFree,
        ]]);
    }

    /**
     * A project from the quote to the final invoice: accepted ones become an order, with a deposit,
     * the hardware bought from the distributor and the balance when the work is done.
     *
     * @param  list<array{0: string, 1: float|int}>  $items  [product key, quantity]
     */
    private function project(CarbonImmutable $day, string $customerKey, string $title, array $items, string $outcome, int $deposit): void
    {
        $customer = $this->customers[$customerKey];
        $contact = $customer->contacts()->first();

        $quote = $this->saveQuote->handle(new Quote, [
            'organization_id' => $customer->id,
            'contact_id' => $contact?->id,
            'owner_id' => $this->user->id,
            'title' => $title,
            'issue_date' => $day->toDateString(),
            'valid_until' => $day->addDays(30)->toDateString(),
            'payment_term_id' => $customer->payment_term_id,
            'intro' => __('Gentile :name, come concordato le invio la proposta per «:title».', ['name' => $contact?->full_name ?? $customer->name, 'title' => $title]),
            'terms' => (string) $this->settings->get('quotes.terms'),
            'lines' => array_map(fn (array $item) => $this->quoteLine($item[0], $item[1]), $items),
        ]);
        $this->stats['quotes']++;

        if ($outcome === 'draft') {
            return;
        }

        $quote->markSent();

        if ($outcome === 'sent') {
            return;
        }

        $decision = $this->workingDay($day->addDays(mt_rand(3, 12)));
        $id = $quote->id;

        if ($outcome === 'expired') {
            $this->schedule($day->addDays(31), fn () => Quote::query()->find($id)?->forceFill(['status' => QuoteStatus::Expired])->save());

            return;
        }

        $this->schedule($decision, function (CarbonImmutable $day) use ($id, $outcome, $deposit, $items, $contact): void {
            $quote = Quote::query()->findOrFail($id);
            $quote->decide($outcome === 'accepted', $contact?->full_name);

            if ($outcome !== 'accepted') {
                return;
            }

            $this->hourPackages->handle($quote);
            $order = $this->saveOrder->fromQuote($quote, $this->user->id);
            $order->forceFill(['status' => OrderStatus::InProgress])->save();
            $this->stats['orders']++;

            // Hour packages are paid in advance.
            $isPackage = collect($items)->every(fn (array $item) => $item[0] === 'package');

            if ($isPackage) {
                $this->issue($this->buildInvoice->fromOrder($order, 'full'));
                $order->forceFill(['status' => OrderStatus::Completed])->save();

                return;
            }

            if ($deposit > 0) {
                $this->issue($this->buildInvoice->fromOrder($order, 'deposit', [], $deposit));
            }

            $hardware = collect($items)->filter(fn (array $item) => $this->products[$item[0]]->type === ProductType::Product)->values()->all();

            if ($hardware !== []) {
                $this->purchase($order, $hardware, $day);
            }

            $orderId = $order->id;
            $this->schedule($this->workingDay($day->addDays(mt_rand(14, 35))), function () use ($orderId): void {
                $order = Order::query()->findOrFail($orderId);

                try {
                    $this->issue($this->buildInvoice->fromOrder($order, 'full'));
                } catch (ValidationException) {
                    return;
                }

                $order->forceFill(['status' => OrderStatus::Completed])->save();
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteLine(string $productKey, float|int $quantity): array
    {
        $product = $this->products[$productKey];

        return [
            'type' => 'item', 'product_id' => $product->id, 'code' => $product->code, 'description' => $product->name,
            'unit' => $product->unit, 'quantity' => $quantity, 'unit_price' => (float) $product->price, 'discount_percent' => 0,
            'vat_rate_id' => $this->vatFree, 'is_optional' => false, 'is_selected' => true,
        ];
    }

    /**
     * The hardware of a project, ordered from the distributor and invoiced by it a few days later.
     *
     * @param  list<array{0: string, 1: float|int}>  $hardware
     */
    private function purchase(Order $order, array $hardware, CarbonImmutable $day): void
    {
        $lines = array_map(fn (array $item) => [
            'type' => 'item', 'product_id' => $this->products[$item[0]]->id, 'code' => $this->products[$item[0]]->code,
            'description' => $this->products[$item[0]]->name, 'unit' => 'pz', 'quantity' => $item[1],
            'unit_price' => (float) $this->products[$item[0]]->cost, 'discount_percent' => 0, 'vat_rate_id' => $this->vat22,
        ], $hardware);

        $purchaseOrder = $this->savePurchaseOrder->handle(new PurchaseOrder, [
            'supplier_id' => $this->suppliers['esprinet']->id, 'owner_id' => $this->user->id, 'customer_id' => $order->organization_id,
            'order_id' => $order->id, 'title' => __('Materiale per :title', ['title' => $order->title]), 'order_date' => $day->toDateString(),
            'payment_term_id' => $this->suppliers['esprinet']->payment_term_id, 'lines' => $lines,
        ]);
        $purchaseOrder->forceFill(['status' => PurchaseOrderStatus::Sent])->save();

        $orderId = $order->id;
        $purchaseOrderId = $purchaseOrder->id;
        $this->schedule($this->workingDay($day->addDays(mt_rand(4, 8))), function () use ($orderId, $purchaseOrderId, $hardware): void {
            PurchaseOrder::query()->find($purchaseOrderId)?->forceFill(['status' => PurchaseOrderStatus::Received])->save();
            $order = Order::query()->findOrFail($orderId);

            $this->expense('esprinet', $this->categories['materials'], __('Materiale per :title', ['title' => $order->title]), array_map(
                fn (array $item) => [$this->products[$item[0]]->name, $item[1], (float) $this->products[$item[0]]->cost],
                $hardware,
            ), payInDays: 30, extra: ['order_id' => $order->id, 'customer_id' => $order->organization_id]);
        });
    }

    // --- Interventions -----------------------------------------------------------------------

    private function interventions(CarbonImmutable $day): void
    {
        $count = $this->weighted([0 => 20, 1 => 45, 2 => 28, 3 => 7]);
        $times = [];

        for ($i = 0; $i < $count; $i++) {
            $customerKey = $this->weighted(array_map(fn (array $profile) => $profile['activity'], $this->profiles));
            $customer = $this->customers[$customerKey];
            $onsite = $this->chance($this->profiles[$customerKey]['onsite']);
            $consulting = $this->chance(0.1);

            do {
                $minute = mt_rand(16, 34) * 30;
            } while (in_array($minute, $times, true));
            $times[] = $minute;

            $duration = $onsite ? mt_rand(4, 24) * 10 : mt_rand(2, 14) * 10;
            $pool = $onsite ? self::ONSITE_JOBS : self::REMOTE_JOBS;
            $description = $pool[mt_rand(0, count($pool) - 1)];
            $warranty = $this->chance(0.06);

            $this->saveIntervention->handle(new Intervention, [
                'organization_id' => $customer->id,
                'location_id' => $onsite ? $customer->locations()->value('id') : null,
                'contact_id' => $customer->contacts()->value('id'),
                'category_id' => $consulting ? $this->consulting->id : $this->support->id,
                'mode' => $onsite ? InterventionMode::Onsite : InterventionMode::Remote,
                'technician_id' => $this->user->id,
                'started_at' => CarbonImmutable::parse($day->toDateString(), config('app.display_timezone'))->addMinutes($minute)->utc(),
                'duration_minutes' => $duration,
                'description' => $warranty ? __('Verifica in garanzia: :job', ['job' => lcfirst($description)]) : $description,
                'billable' => ! $warranty,
                'callout' => $onsite && $this->chance(0.6),
                'km' => $onsite ? mt_rand(6, 38) : 0,
                'travel_minutes' => $onsite ? mt_rand(2, 6) * 10 : 0,
                'materials' => $onsite && $this->chance(0.15) ? [$this->material()] : null,
            ]);
            $this->stats['interventions']++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function material(): array
    {
        $product = $this->products[$this->chance(0.5) ? 'cable' : 'ssd'];

        return [
            'product_id' => $product->id, 'code' => $product->code, 'description' => $product->name, 'unit' => 'pz',
            'quantity' => $product->code === 'P00010' ? mt_rand(2, 6) : 1, 'unit_price' => (float) $product->price, 'vat_rate_id' => $this->vatFree,
        ];
    }

    private const REMOTE_JOBS = [
        'Configurazione account di posta su Outlook e smartphone',
        'Ripristino accesso VPN per lo smart working',
        'Aggiornamento antivirus e analisi di una segnalazione',
        'Verifica backup notturno non riuscito e nuovo avvio',
        'Reset password Microsoft 365 e attivazione MFA',
        'Installazione driver della stampante di rete',
        'Pulizia spazio disco sul server e rotazione log',
        'Aggiornamenti di Windows sul server e riavvio programmato',
        'Permessi sulle cartelle condivise del NAS',
        'Supporto per firma digitale e lettore smart card',
        'Analisi lentezza del gestionale e ottimizzazione database',
        'Rinnovo certificato SSL del sito',
        'Configurazione regola sul firewall per il nuovo fornitore',
        'Recupero file cancellati dalla cartella condivisa',
        'Configurazione inoltro e risposta automatica della posta',
        'Assistenza all\'utente sul gestionale fatture',
        'Verifica allarme del monitoraggio: disco quasi pieno',
        'Installazione aggiornamento del software di contabilità',
        'Configurazione MFA per un nuovo collaboratore',
        'Controllo log del firewall dopo tentativi di accesso',
    ];

    private const ONSITE_JOBS = [
        'Sostituzione switch di piano e riordino del cablaggio',
        'Installazione nuovo PC e trasferimento dati utente',
        'Configurazione access point per la rete ospiti',
        'Intervento sul gruppo di continuità in allarme',
        'Sostituzione disco guasto sul NAS e ricostruzione RAID',
        'Configurazione della stampante multifunzione in rete',
        'Verifica della rete dopo un blackout',
        'Cablaggio nuova postazione in ufficio',
        'Spostamento del server nel nuovo armadio rack',
        'Formazione al personale sull\'uso sicuro della posta',
        'Sopralluogo per il preventivo di ampliamento rete',
        'Sostituzione SSD di un portatile lento',
    ];

    // --- Expenses and taxes ------------------------------------------------------------------

    /**
     * Rent, phone and software every month; the accountant every quarter; once a year the renewals.
     */
    private function monthlyCosts(CarbonImmutable $day): void
    {
        $month = $day->locale('it')->translatedFormat('F Y');

        $this->expense('coworking', $this->categories['rent'], __('Postazione in coworking – :month', ['month' => $month]), [[__('Postazione fissa e sala riunioni'), 1, 200]], payInDays: 5, method: 'bank_transfer');
        $this->expense('tim', $this->categories['utilities'], __('Fibra e mobile – :month', ['month' => $month]), [[__('Linea fibra ufficio'), 1, 24.50], [__('Abbonamento mobile'), 1, 8.20]], payInDays: 15, method: 'direct_debit');
        $this->expense('microsoft', $this->categories['software'], __('Microsoft 365 Business Standard – :month', ['month' => $month]), [[__('Licenza Microsoft 365 Business Standard'), 1, 11.70]], payInDays: 0, method: 'card');
        // What the professional takes home: a transfer to the personal account, not a cost.
        if ($day->gt($this->start->addMonth())) {
            $uuid = (string) Str::uuid();

            foreach ([[$this->bank, -1800], [$this->personal, 1800]] as [$account, $amount]) {
                AccountMovement::query()->create([
                    'financial_account_id' => $account->id, 'date' => $day->toDateString(), 'amount' => $amount,
                    'description' => __('Giroconto al conto personale'), 'transfer_uuid' => $uuid, 'user_id' => $this->user->id,
                ]);
            }
        }

        AccountMovement::query()->create([
            'financial_account_id' => $this->bank->id, 'date' => $day->toDateString(), 'amount' => -6.50,
            'description' => __('Canone mensile del conto'), 'category_id' => $this->categories['bank'], 'user_id' => $this->user->id,
        ]);

        if (in_array($day->month, [1, 4, 7, 10], true)) {
            $this->expense('accountant', $this->categories['consulting'], __('Tenuta contabilità e dichiarativi – trimestre'), [[__('Assistenza contabile regime forfettario'), 1, 250]], payInDays: 10, method: 'bank_transfer');
        }

        if ($day->month === 1) {
            $this->expense('aruba', $this->categories['software'], __('Rinnovo domini e caselle dei clienti'), [[__('Rinnovo domini .it'), 6, 9.90], [__('Hosting e caselle di posta'), 6, 32]], payInDays: 0, method: 'card');
            $this->expense('microsoft', $this->categories['software'], __('Strumenti di monitoraggio – licenza annuale'), [[__('Licenza annuale piattaforma di monitoraggio'), 1, 240]], payInDays: 0, method: 'card');
        }

        if ($day->month === 3) {
            $this->expense('insurance', $this->categories['other'], __('Polizza RC professionale'), [[__('Premio annuale RC professionale'), 1, 380]], payInDays: 15, method: 'bank_transfer', vatRateId: $this->vatExempt);
        }
    }

    private function smallExpenses(CarbonImmutable $day): void
    {
        if ($this->chance(0.17)) {
            $gross = mt_rand(3500, 7500) / 100;
            $this->expense('fuel', $this->categories['fuel'], __('Carburante'), [[__('Gasolio'), 1, round($gross / 1.22, 2)]], type: 'receipt', payInDays: 0, method: 'card');
        }

        if ($this->chance(0.025)) {
            $this->expense('amazon', $this->categories['materials'], __('Piccolo materiale di consumo'), [[__('Cavi, adattatori e minuteria'), 1, mt_rand(1500, 9000) / 100]], payInDays: 0, method: 'card');
        }
    }

    /**
     * An expense of a supplier, paid at once or on its due date.
     *
     * @param  list<array{0: string, 1: float|int, 2: float}>  $lines  [description, quantity, net price]
     * @param  array<string, mixed>  $extra
     */
    private function expense(string $supplierKey, int $categoryId, string $title, array $lines, string $type = 'invoice', int $payInDays = 0, string $method = 'bank_transfer', array $extra = [], ?int $vatRateId = null): void
    {
        $supplier = $this->suppliers[$supplierKey];
        $date = CarbonImmutable::parse(today()->toDateString());
        $sequence = ($this->documentNumbers["{$supplierKey}-{$date->year}"] ?? 0) + 1;
        $this->documentNumbers["{$supplierKey}-{$date->year}"] = $sequence;
        $paidNow = $payInDays === 0;

        $expense = $this->saveExpense->handle(new Expense, [
            'type' => $type,
            'category_id' => $categoryId,
            'supplier_id' => $supplier->id,
            'number' => $type === 'receipt' ? sprintf('%04d-%03d', $date->dayOfYear, $sequence) : sprintf('%s/%d', $sequence, $date->year),
            'document_date' => $date->toDateString(),
            'title' => $title,
            'payment_method' => $method,
            'due_date' => $date->addDays($payInDays)->toDateString(),
            'paid_at' => $paidNow ? $date->toDateString() : null,
            'owner_id' => $this->user->id,
            ...$extra,
            'lines' => array_map(fn (array $line) => [
                'type' => 'item', 'description' => $line[0], 'quantity' => $line[1], 'unit_price' => $line[2],
                'discount_percent' => 0, 'vat_rate_id' => $vatRateId ?? $this->vat22, 'rebill' => false, 'markup_percent' => 0,
            ], $lines),
        ]);
        $this->stats['expenses']++;

        if ($paidNow) {
            $this->syncExpensePayment->handle($expense, $this->bank->id);

            return;
        }

        $id = $expense->id;
        $this->schedule($date->addDays($payInDays), function (CarbonImmutable $day) use ($id): void {
            $expense = Expense::query()->find($id);

            if ($expense && $expense->paid_at === null) {
                $expense->forceFill(['paid_at' => $day->toDateString()])->save();
                $this->syncExpensePayment->handle($expense, $this->bank->id);
            }
        });
    }

    /**
     * F24 of the flat-rate tax and of the INPS contributions: balance and first advance in June,
     * second advance in November, from what was collected the year before.
     */
    private function taxes(CarbonImmutable $day): void
    {
        $collected = (float) AccountMovement::query()->whereNotNull('invoice_id')->whereYear('date', $day->year - 1)->sum('amount');
        $taxable = $collected * 0.67;

        if ($taxable <= 0) {
            return;
        }

        $share = $day->month === 6 ? 0.9 : 0.55;
        $label = $day->month === 6 ? __('saldo :previous e primo acconto :year', ['previous' => $day->year - 1, 'year' => $day->year]) : __('secondo acconto :year', ['year' => $day->year]);

        foreach ([
            [$this->categories['taxes'], __('F24 imposta sostitutiva – :label', ['label' => $label]), $taxable * 0.05],
            [$this->categories['contributions'], __('F24 contributi INPS gestione separata – :label', ['label' => $label]), $taxable * 0.2607],
        ] as [$category, $description, $amount]) {
            AccountMovement::query()->create([
                'financial_account_id' => $this->bank->id, 'date' => $day->toDateString(), 'amount' => -round($amount * $share, 2),
                'description' => $description, 'category_id' => $category, 'user_id' => $this->user->id,
            ]);
        }
    }

    // --- Helpers -----------------------------------------------------------------------------

    /**
     * The clock of the application on that day (evening, so everything of the day is in the past;
     * today the real time).
     */
    private function at(CarbonImmutable $day): void
    {
        Carbon::setTestNow($day->isSameDay($this->end)
            ? $this->realNow
            : CarbonImmutable::parse($day->toDateString().' 18:30', config('app.display_timezone'))->utc());
    }

    private function schedule(CarbonImmutable $date, Closure $action): void
    {
        $date = $date->startOfDay();

        if ($date->gt($this->end)) {
            return;
        }

        $this->agenda[$date->toDateString()][] = $action;
    }

    private function workingDay(CarbonImmutable $day): CarbonImmutable
    {
        while ($day->isWeekend()) {
            $day = $day->addDay();
        }

        return $day->startOfDay();
    }

    private function isFirstWorkingDay(CarbonImmutable $day): bool
    {
        return $day->isSameDay($this->workingDay($day->startOfMonth()));
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return TKey
     */
    private function weighted(array $weights): int|string
    {
        $pick = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /**
     * An Italian VAT number from its first ten digits, with the right check digit.
     */
    public static function partitaIva(string $digits): string
    {
        $digits = substr(str_pad(preg_replace('/\D/', '', $digits), 10, '0', STR_PAD_LEFT), 0, 10);
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $digits[$i];

            if ($i % 2 === 1) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
        }

        return $digits.((10 - $sum % 10) % 10);
    }

    /**
     * A personal tax code from its first fifteen characters, with the right check letter.
     */
    public static function taxCode(string $first15): string
    {
        $odd = ['0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
            'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21, 'K' => 2, 'L' => 4, 'M' => 18,
            'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14, 'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23];
        $sum = 0;

        foreach (str_split(strtoupper($first15)) as $position => $char) {
            $sum += $position % 2 === 0
                ? $odd[$char]
                : (ctype_digit($char) ? (int) $char : ord($char) - ord('A'));
        }

        return strtoupper($first15).chr(ord('A') + $sum % 26);
    }
}
