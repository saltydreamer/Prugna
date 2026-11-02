<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { countries } from '@/utils/countries';

const props = defineProps({
    tab: { type: String, default: 'company' },
    company: { type: Object, required: true },
    mail: { type: Object, required: true },
    quotes: { type: Object, required: true },
    paymentTerms: { type: Array, required: true },
    paymentMethods: { type: Array, required: true },
});

const { confirm } = useConfirm();

const tabs = ['company', 'mail', 'quotes', 'payments'];
const current = ref(tabs.includes(props.tab) ? props.tab : 'company');

watch(current, (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
});

const options = { preserveScroll: true, preserveState: true };

// --- Company --------------------------------------------------------------------
const companyFields = [
    'name',
    'vat_number',
    'tax_code',
    'rea',
    'share_capital',
    'address',
    'postal_code',
    'city',
    'province',
    'country',
    'email',
    'pec',
    'phone',
    'website',
    'bank_name',
    'iban',
];
const companyForm = useForm(Object.fromEntries(companyFields.map((field) => [field, props.company[field] ?? (field === 'country' ? 'IT' : '')])));

function saveCompany() {
    companyForm.put(route('settings.company.update'), { ...options, onSuccess: () => companyForm.defaults() });
}

const logoInput = ref(null);
const logoForm = useForm({ logo: null });

function uploadLogo(event) {
    const [file] = event.target.files ?? [];

    if (!file) {
        return;
    }

    logoForm.logo = file;
    logoForm.post(route('settings.company.logo.update'), {
        ...options,
        forceFormData: true,
        onFinish: () => {
            logoForm.reset();
            event.target.value = '';
        },
    });
}

function removeLogo() {
    router.delete(route('settings.company.logo.destroy'), options);
}

// --- Mail -------------------------------------------------------------------------
const mailForm = useForm({
    enabled: props.mail.enabled ?? false,
    host: props.mail.host ?? '',
    port: props.mail.port ?? 587,
    encryption: props.mail.encryption ?? 'tls',
    username: props.mail.username ?? '',
    password: '',
    from_address: props.mail.from_address ?? '',
    from_name: props.mail.from_name ?? '',
});

const presets = [
    { title: 'Microsoft 365 / Outlook', host: 'smtp.office365.com', port: 587, encryption: 'tls' },
    { title: 'Google Workspace / Gmail', host: 'smtp.gmail.com', port: 587, encryption: 'tls' },
    { title: 'Aruba', host: 'smtps.aruba.it', port: 465, encryption: 'ssl' },
];

function applyPreset(preset) {
    Object.assign(mailForm, { host: preset.host, port: preset.port, encryption: preset.encryption });
}

function saveMail() {
    mailForm.put(route('settings.mail.update'), { ...options, onSuccess: () => mailForm.reset('password') });
}

// Test email: the recipient is chosen in a small dialog (default: the current user's address).
const page = usePage();
const testDialog = ref(false);
const testForm = useForm({ to: '' });

function openTest() {
    testForm.clearErrors();
    testForm.to = testForm.to || props.company.email || page.props.auth.user.email;
    testDialog.value = true;
}

function sendTest() {
    testForm.post(route('settings.mail.test'), { ...options, onSuccess: () => (testDialog.value = false) });
}

// --- Quotes ------------------------------------------------------------------------
const quotesForm = useForm({
    validity_days: props.quotes.validity_days ?? 30,
    terms: props.quotes.terms ?? '',
    email_subject: props.quotes.email_subject ?? '',
    email_body: props.quotes.email_body ?? '',
});

function saveQuotes() {
    quotesForm.put(route('settings.quotes.update'), { ...options, onSuccess: () => quotesForm.defaults() });
}

const placeholders = ['{numero}', '{cliente}', '{titolo}', '{totale}', '{scadenza}', '{link}', '{mittente}', '{azienda}'];

// --- Payment terms -----------------------------------------------------------------
const termDialog = ref(false);
const editingTerm = ref(null);
const termForm = useForm({ name: '', method: 'MP05', days: 30, end_of_month: false, description: '', is_default: false, is_active: true });

const methodLabel = computed(() => Object.fromEntries(props.paymentMethods.map((method) => [method.value, method.title])));

function openTerm(term = null) {
    editingTerm.value = term;
    termForm.clearErrors();
    termForm.defaults({
        name: term?.name ?? '',
        method: term?.method ?? 'MP05',
        days: term?.days ?? 30,
        end_of_month: term?.end_of_month ?? false,
        description: term?.description ?? '',
        is_default: term?.is_default ?? false,
        is_active: term?.is_active ?? true,
    });
    termForm.reset();
    termDialog.value = true;
}

function saveTerm() {
    const done = { ...options, onSuccess: () => (termDialog.value = false) };

    if (editingTerm.value) {
        termForm.put(route('settings.payment-terms.update', editingTerm.value.id), done);
    } else {
        termForm.post(route('settings.payment-terms.store'), done);
    }
}

async function deleteTerm(term) {
    const confirmed = await confirm({
        title: `Eliminare "${term.name}"?`,
        message: term.organizations_count ? `${term.organizations_count} clienti la usano come predefinita: torneranno a quella generale.` : '',
        confirmText: 'Elimina',
        color: 'error',
    });

    if (confirmed) {
        router.delete(route('settings.payment-terms.destroy', term.id), options);
    }
}

function dueLabel(term) {
    if (!term.days) {
        return term.end_of_month ? 'fine mese' : 'immediato';
    }

    return `${term.days} gg${term.end_of_month ? ' fine mese' : ''}`;
}
</script>

<template>
    <div>
        <PageHeader title="Impostazioni" subtitle="Dati dell'azienda, invio email, preventivi e condizioni di pagamento." />

        <v-card class="mb-6">
            <v-tabs v-model="current" color="primary" show-arrows>
                <v-tab value="company" prepend-icon="mdi-domain">Azienda</v-tab>
                <v-tab value="mail" prepend-icon="mdi-email-fast-outline">Email</v-tab>
                <v-tab value="quotes" prepend-icon="mdi-file-document-edit-outline">Preventivi</v-tab>
                <v-tab value="payments" prepend-icon="mdi-cash-clock">Pagamenti</v-tab>
            </v-tabs>
        </v-card>

        <v-tabs-window v-model="current">
            <!-- Company -->
            <v-tabs-window-item value="company">
                <v-card class="mb-6" title="Logo" subtitle="Usato nell'intestazione di preventivi e documenti. PNG o JPG, massimo 1 MB.">
                    <v-card-text class="d-flex flex-wrap align-center ga-4">
                        <v-sheet border rounded="lg" class="pa-3 d-flex align-center justify-center" width="220" height="90">
                            <v-img v-if="company.logo_url" :src="company.logo_url" max-height="70" contain />
                            <span v-else class="text-medium-emphasis text-caption">Nessun logo</span>
                        </v-sheet>
                        <input ref="logoInput" type="file" accept="image/png,image/jpeg" hidden @change="uploadLogo" />
                        <v-btn color="primary" variant="tonal" prepend-icon="mdi-upload" :loading="logoForm.processing" @click="logoInput.click()">
                            Carica logo
                        </v-btn>
                        <v-btn v-if="company.logo_url" variant="text" color="error" @click="removeLogo">Rimuovi</v-btn>
                        <div v-if="logoForm.errors.logo" class="text-error text-body-2 w-100">{{ logoForm.errors.logo }}</div>
                    </v-card-text>
                </v-card>

                <v-card title="Dati azienda">
                    <v-form @submit.prevent="saveCompany">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12">
                                    <v-text-field v-model="companyForm.name" label="Ragione sociale" :error-messages="companyForm.errors.name" />
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-text-field
                                        v-model="companyForm.vat_number"
                                        label="Partita IVA"
                                        :error-messages="companyForm.errors.vat_number"
                                    />
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-text-field
                                        v-model="companyForm.tax_code"
                                        label="Codice fiscale"
                                        :error-messages="companyForm.errors.tax_code"
                                    />
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-text-field
                                        v-model="companyForm.rea"
                                        label="REA"
                                        placeholder="MI-1234567"
                                        :error-messages="companyForm.errors.rea"
                                    />
                                </v-col>
                                <v-col cols="12" md="3">
                                    <v-text-field
                                        v-model="companyForm.share_capital"
                                        label="Capitale sociale"
                                        placeholder="€ 10.000,00 i.v."
                                        :error-messages="companyForm.errors.share_capital"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="companyForm.address" label="Indirizzo" :error-messages="companyForm.errors.address" />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field v-model="companyForm.postal_code" label="CAP" :error-messages="companyForm.errors.postal_code" />
                                </v-col>
                                <v-col cols="8" md="2">
                                    <v-text-field v-model="companyForm.city" label="Città" :error-messages="companyForm.errors.city" />
                                </v-col>
                                <v-col cols="4" md="1">
                                    <v-text-field
                                        v-model="companyForm.province"
                                        label="Prov."
                                        maxlength="2"
                                        :error-messages="companyForm.errors.province"
                                    />
                                </v-col>
                                <v-col cols="8" md="1">
                                    <v-autocomplete
                                        v-model="companyForm.country"
                                        :items="countries"
                                        label="Naz."
                                        :error-messages="companyForm.errors.country"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="companyForm.email" label="Email" type="email" :error-messages="companyForm.errors.email" />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="companyForm.pec" label="PEC" type="email" :error-messages="companyForm.errors.pec" />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="companyForm.phone" label="Telefono" :error-messages="companyForm.errors.phone" />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="companyForm.website" label="Sito web" :error-messages="companyForm.errors.website" />
                                </v-col>
                                <v-col cols="12" md="5">
                                    <v-text-field v-model="companyForm.bank_name" label="Banca" :error-messages="companyForm.errors.bank_name" />
                                </v-col>
                                <v-col cols="12" md="7">
                                    <v-text-field
                                        v-model="companyForm.iban"
                                        label="IBAN"
                                        hint="Riportato nei documenti con pagamento tramite bonifico"
                                        persistent-hint
                                        :error-messages="companyForm.errors.iban"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4">
                            <v-spacer />
                            <v-btn type="submit" color="primary" variant="flat" :loading="companyForm.processing" :disabled="!companyForm.isDirty">
                                Salva
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card>
            </v-tabs-window-item>

            <!-- Mail -->
            <v-tabs-window-item value="mail">
                <v-card>
                    <v-card-item>
                        <v-card-title>Posta in uscita (SMTP)</v-card-title>
                        <v-card-subtitle class="text-wrap">Server usato per inviare preventivi, notifiche e reset password.</v-card-subtitle>
                        <template #append>
                            <v-chip :color="mail.enabled && mail.host ? 'success' : 'warning'" variant="tonal">
                                {{ mail.enabled && mail.host ? 'Attivo' : 'Non configurato' }}
                            </v-chip>
                        </template>
                    </v-card-item>
                    <v-form @submit.prevent="saveMail">
                        <v-card-text>
                            <v-switch v-model="mailForm.enabled" label="Invia le email con questo server" class="mb-2" />

                            <div class="d-flex flex-wrap align-center ga-2 mb-4">
                                <span class="text-caption text-medium-emphasis">Impostazioni rapide:</span>
                                <v-chip v-for="preset in presets" :key="preset.title" variant="outlined" @click="applyPreset(preset)">
                                    {{ preset.title }}
                                </v-chip>
                            </div>

                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="mailForm.host"
                                        label="Server SMTP"
                                        placeholder="smtp.esempio.it"
                                        :error-messages="mailForm.errors.host"
                                    />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model="mailForm.port" label="Porta" type="number" :error-messages="mailForm.errors.port" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select
                                        v-model="mailForm.encryption"
                                        :items="[
                                            { value: 'tls', title: 'STARTTLS (587)' },
                                            { value: 'ssl', title: 'SSL/TLS (465)' },
                                            { value: 'none', title: 'Nessuna' },
                                        ]"
                                        label="Sicurezza"
                                        :error-messages="mailForm.errors.encryption"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="mailForm.username"
                                        label="Utente"
                                        autocomplete="off"
                                        :error-messages="mailForm.errors.username"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="mailForm.password"
                                        label="Password"
                                        type="password"
                                        autocomplete="new-password"
                                        :placeholder="mail.has_password ? '•••••••• (salvata)' : ''"
                                        :hint="mail.has_password ? 'Lascia vuoto per mantenere quella salvata' : 'Conservata cifrata'"
                                        persistent-hint
                                        :error-messages="mailForm.errors.password"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="mailForm.from_address"
                                        label="Indirizzo mittente"
                                        type="email"
                                        :error-messages="mailForm.errors.from_address"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="mailForm.from_name"
                                        label="Nome mittente"
                                        :placeholder="company.name || 'Nome azienda'"
                                        :error-messages="mailForm.errors.from_name"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4 flex-wrap ga-2">
                            <v-btn
                                variant="tonal"
                                prepend-icon="mdi-email-check-outline"
                                :disabled="mailForm.isDirty"
                                :title="mailForm.isDirty ? 'Salva le modifiche prima di inviare la prova' : undefined"
                                @click="openTest"
                            >
                                Invia email di prova
                            </v-btn>
                            <v-spacer />
                            <v-btn type="submit" color="primary" variant="flat" :loading="mailForm.processing">Salva</v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card>
            </v-tabs-window-item>

            <!-- Quotes -->
            <v-tabs-window-item value="quotes">
                <v-card title="Preventivi">
                    <v-form @submit.prevent="saveQuotes">
                        <v-card-text>
                            <v-text-field
                                v-model="quotesForm.validity_days"
                                label="Validità predefinita"
                                type="number"
                                suffix="giorni"
                                style="max-width: 240px"
                                :error-messages="quotesForm.errors.validity_days"
                                class="mb-2"
                            />
                            <v-textarea
                                v-model="quotesForm.terms"
                                label="Condizioni generali"
                                hint="Riportate in fondo a ogni nuovo preventivo (modificabili nel singolo documento)"
                                persistent-hint
                                rows="4"
                                auto-grow
                                :error-messages="quotesForm.errors.terms"
                                class="mb-4"
                            />
                            <div class="text-subtitle-1 font-weight-medium mt-2 mb-2">Email di invio</div>
                            <v-text-field
                                v-model="quotesForm.email_subject"
                                label="Oggetto"
                                :error-messages="quotesForm.errors.email_subject"
                                class="mb-2"
                            />
                            <v-textarea
                                v-model="quotesForm.email_body"
                                label="Testo"
                                rows="8"
                                auto-grow
                                :error-messages="quotesForm.errors.email_body"
                            />
                            <div class="d-flex flex-wrap ga-1 align-center">
                                <span class="text-caption text-medium-emphasis mr-1">Segnaposto:</span>
                                <v-chip v-for="placeholder in placeholders" :key="placeholder" size="x-small" variant="outlined">
                                    {{ placeholder }}
                                </v-chip>
                            </div>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4">
                            <v-spacer />
                            <v-btn type="submit" color="primary" variant="flat" :loading="quotesForm.processing" :disabled="!quotesForm.isDirty">
                                Salva
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card>
            </v-tabs-window-item>

            <!-- Payment terms -->
            <v-tabs-window-item value="payments">
                <v-card>
                    <v-card-item>
                        <v-card-title>Condizioni di pagamento</v-card-title>
                        <v-card-subtitle class="text-wrap">
                            Ogni cliente può averne una predefinita (dalla sua scheda); altrimenti si usa quella generale.
                        </v-card-subtitle>
                        <template #append>
                            <v-btn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openTerm()">Nuova</v-btn>
                        </template>
                    </v-card-item>
                    <v-divider />
                    <v-list class="py-0">
                        <template v-for="(term, index) in paymentTerms" :key="term.id">
                            <v-divider v-if="index > 0" />
                            <v-list-item :class="{ 'text-disabled': !term.is_active }">
                                <v-list-item-title class="font-weight-medium">
                                    {{ term.name }}
                                    <v-chip v-if="term.is_default" color="primary" variant="tonal" class="ml-1">Predefinita</v-chip>
                                    <v-chip v-if="!term.is_active" variant="outlined" class="ml-1">Disattivata</v-chip>
                                </v-list-item-title>
                                <v-list-item-subtitle>
                                    {{ methodLabel[term.method] }} · scadenza {{ dueLabel(term) }}
                                    <template v-if="term.organizations_count">· {{ term.organizations_count }} clienti</template>
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn icon="mdi-pencil-outline" variant="text" size="small" title="Modifica" @click="openTerm(term)" />
                                    <v-btn
                                        v-if="!term.is_default"
                                        icon="mdi-delete-outline"
                                        variant="text"
                                        size="small"
                                        color="error"
                                        title="Elimina"
                                        @click="deleteTerm(term)"
                                    />
                                </template>
                            </v-list-item>
                        </template>
                    </v-list>
                </v-card>
            </v-tabs-window-item>
        </v-tabs-window>

        <v-dialog v-model="testDialog" max-width="480">
            <v-card title="Email di prova" subtitle="Verifica che il server di posta funzioni.">
                <v-form @submit.prevent="sendTest">
                    <v-card-text>
                        <v-text-field
                            v-model="testForm.to"
                            label="Invia a"
                            type="email"
                            prepend-inner-icon="mdi-email-outline"
                            hint="Usa una casella che puoi controllare subito"
                            persistent-hint
                            :error-messages="testForm.errors.to"
                            autofocus
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="testDialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" prepend-icon="mdi-send-outline" :loading="testForm.processing">
                            Invia
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>

        <v-dialog v-model="termDialog" max-width="560">
            <v-card :title="editingTerm ? 'Modifica condizione' : 'Nuova condizione di pagamento'">
                <v-form @submit.prevent="saveTerm">
                    <v-card-text>
                        <v-row dense>
                            <v-col cols="12">
                                <v-text-field
                                    v-model="termForm.name"
                                    label="Nome"
                                    placeholder="Bonifico bancario 30 gg d.f.f.m."
                                    :error-messages="termForm.errors.name"
                                    autofocus
                                />
                            </v-col>
                            <v-col cols="12">
                                <v-select
                                    v-model="termForm.method"
                                    :items="paymentMethods"
                                    label="Modalità (fattura elettronica)"
                                    :error-messages="termForm.errors.method"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field v-model="termForm.days" label="Giorni" type="number" min="0" :error-messages="termForm.errors.days" />
                            </v-col>
                            <v-col cols="6">
                                <v-switch v-model="termForm.end_of_month" label="Fine mese" />
                            </v-col>
                            <v-col cols="12">
                                <v-textarea
                                    v-model="termForm.description"
                                    label="Note per il cliente"
                                    rows="2"
                                    auto-grow
                                    :error-messages="termForm.errors.description"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-switch v-model="termForm.is_default" label="Predefinita" />
                            </v-col>
                            <v-col cols="6">
                                <v-switch v-model="termForm.is_active" label="Attiva" :disabled="termForm.is_default" />
                            </v-col>
                        </v-row>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="termDialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" :loading="termForm.processing">Salva</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </div>
</template>
