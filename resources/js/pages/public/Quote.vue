<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { quoteTotals, vatLabel } from '@/utils/quoteTotals';

const props = defineProps({
    quote: { type: Object, required: true },
    company: { type: Object, required: true },
    token: { type: String, required: true },
});

// Optional lines the customer chooses to include.
const selection = reactive(Object.fromEntries(props.quote.lines.filter((line) => line.is_optional).map((line) => [line.id, line.is_selected])));

const lines = computed(() => props.quote.lines.map((line) => (line.is_optional && props.quote.is_open ? { ...line, is_selected: !!selection[line.id] } : line)));
const totals = computed(() => (props.quote.is_open ? quoteTotals(lines.value) : props.quote.totals));
const hasOptional = computed(() => props.quote.lines.some((line) => line.is_optional));
// No "+ IVA" when the options carry no VAT (VAT-free regimes).
const optionalWithVat = computed(() => props.quote.lines.some((line) => line.is_optional && Number(line.vat_rate) > 0));

const acceptDialog = ref(false);
const rejectDialog = ref(false);

const acceptForm = useForm({ name: props.quote.contact ?? '', note: '', optional_lines: [], agree: false });
const rejectForm = useForm({ name: props.quote.contact ?? '', note: '' });

function accept() {
    acceptForm
        .transform((data) => ({ ...data, optional_lines: Object.keys(selection).filter((id) => selection[id]).map(Number) }))
        .post(route('quotes.public.accept', props.token), { preserveScroll: true, onSuccess: () => (acceptDialog.value = false) });
}

function reject() {
    rejectForm.post(route('quotes.public.reject', props.token), { preserveScroll: true, onSuccess: () => (rejectDialog.value = false) });
}

const statusAlert = computed(() => {
    switch (props.quote.status) {
        case 'accepted':
            return { type: 'success', text: `Preventivo accettato${props.quote.decision_name ? ` da ${props.quote.decision_name}` : ''} il ${formatDateTime(props.quote.decided_at)}. Grazie!` };
        case 'rejected':
            return { type: 'info', text: 'Il preventivo è stato rifiutato. Grazie per averci risposto.' };
        case 'expired':
            return { type: 'warning', text: 'Il preventivo è scaduto. Contattaci per un\'offerta aggiornata.' };
        default:
            return props.quote.is_open ? null : { type: 'warning', text: 'Il preventivo è scaduto. Contattaci per un\'offerta aggiornata.' };
    }
});
</script>

<template>
    <div>
        <Head :title="`Preventivo ${quote.number}`" />

        <div class="d-flex flex-wrap align-center justify-space-between ga-4 mb-6">
            <div class="d-flex align-center ga-3">
                <v-img v-if="company.logo_url" :src="company.logo_url" max-height="56" max-width="200" contain />
                <div v-else class="text-h6 font-weight-bold">{{ company.name }}</div>
            </div>
            <v-btn variant="tonal" prepend-icon="mdi-file-pdf-box" :href="route('quotes.public.pdf', token)">Scarica PDF</v-btn>
        </div>

        <v-alert v-if="statusAlert" :type="statusAlert.type" variant="tonal" class="mb-6">{{ statusAlert.text }}</v-alert>

        <v-card class="mb-6">
            <v-card-text>
                <v-row>
                    <v-col cols="12" md="6">
                        <div class="text-overline text-medium-emphasis">Preventivo</div>
                        <div class="text-h5 font-weight-bold">{{ quote.number }}</div>
                        <div class="text-medium-emphasis">del {{ formatDate(quote.issue_date) }} · valido fino al {{ formatDate(quote.valid_until) }}</div>
                        <div v-if="quote.title" class="text-subtitle-1 font-weight-medium mt-3">{{ quote.title }}</div>
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-sheet border rounded="lg" class="pa-4">
                            <div class="text-overline text-medium-emphasis">Spett.le</div>
                            <div class="font-weight-bold">{{ quote.customer.name }}</div>
                            <div v-if="quote.customer.address">{{ quote.customer.address }}</div>
                            <div v-if="quote.customer.city_line">{{ quote.customer.city_line }}</div>
                            <div v-if="quote.customer.vat_number" class="text-medium-emphasis">P.IVA {{ quote.customer.vat_number }}</div>
                            <div v-if="quote.contact" class="text-medium-emphasis">Alla c.a. {{ quote.contact }}</div>
                        </v-sheet>
                    </v-col>
                </v-row>
                <p v-if="quote.intro" class="text-pre-line mt-4 mb-0">{{ quote.intro }}</p>
            </v-card-text>
        </v-card>

        <v-card class="mb-6">
            <v-list class="py-0">
                <template v-for="line in lines" :key="line.id">
                    <v-list-subheader v-if="line.type === 'section'" class="text-primary font-weight-bold text-body-1 bg-surface-light">
                        {{ line.description }}
                    </v-list-subheader>
                    <v-list-item v-else-if="line.type === 'text'">
                        <div class="text-pre-line text-medium-emphasis">{{ line.description }}</div>
                    </v-list-item>
                    <v-list-item v-else :class="{ 'text-medium-emphasis': line.is_optional && !line.is_selected }" class="py-3">
                        <template v-if="line.is_optional" #prepend>
                            <v-checkbox-btn v-if="quote.is_open" v-model="selection[line.id]" color="primary" />
                            <v-icon v-else :icon="line.is_selected ? 'mdi-checkbox-marked-outline' : 'mdi-checkbox-blank-outline'" class="mr-2" />
                        </template>
                        <div class="d-flex flex-wrap justify-space-between ga-2">
                            <div style="flex: 1 1 320px">
                                <div class="text-pre-line">{{ line.description }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    <template v-if="line.code">{{ line.code }} · </template>
                                    {{ line.quantity }} {{ line.unit }} × {{ formatMoney(line.unit_price) }}
                                    <template v-if="line.discount_percent">· sconto {{ line.discount_percent }}%</template>
                                    · {{ line.vat_nature ?? `IVA ${line.vat_rate}%` }}
                                </div>
                                <v-chip v-if="line.is_optional" size="x-small" color="secondary" variant="tonal" class="mt-1">Facoltativa</v-chip>
                                <div v-if="line.package_hours" class="text-caption mt-1">
                                    Pacchetto di {{ line.package_hours }} ore prepagate{{ line.package_months ? `, valide ${line.package_months} mesi` : '' }},
                                    scalate dagli interventi.
                                </div>
                            </div>
                            <div class="font-weight-medium text-no-wrap">{{ formatMoney(line.total) }}</div>
                        </div>
                    </v-list-item>
                    <v-divider />
                </template>
            </v-list>

            <v-card-text>
                <v-row>
                    <v-col cols="12" md="6">
                        <div v-for="group in totals.vat_summary" :key="`${group.rate}-${group.nature}`" class="text-body-2 text-medium-emphasis">
                            {{ vatLabel(group) }}: imponibile {{ formatMoney(group.taxable) }}, imposta {{ formatMoney(group.vat) }}
                        </div>
                        <div v-if="totals.optional_total > 0" class="text-body-2 text-medium-emphasis mt-2">
                            Voci facoltative non incluse: {{ formatMoney(totals.optional_total) }}{{ optionalWithVat ? ' + IVA' : '' }}
                        </div>
                        <v-alert v-if="hasOptional && quote.is_open" type="info" variant="tonal" density="compact" class="mt-3">
                            Seleziona le voci facoltative che vuoi includere: il totale si aggiorna subito.
                        </v-alert>
                    </v-col>
                    <v-col cols="12" md="6">
                        <div class="d-flex justify-space-between"><span>Imponibile</span><span>{{ formatMoney(totals.subtotal) }}</span></div>
                        <div class="d-flex justify-space-between"><span>IVA</span><span>{{ formatMoney(totals.vat_total) }}</span></div>
                        <v-divider class="my-2" />
                        <div class="d-flex justify-space-between text-h6 font-weight-bold text-primary">
                            <span>Totale</span><span>{{ formatMoney(totals.total) }}</span>
                        </div>
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>

        <v-row class="mb-6">
            <v-col v-if="quote.payment_terms" cols="12" md="6">
                <v-card class="h-100" title="Pagamento" :text="quote.payment_terms" />
            </v-col>
            <v-col v-if="quote.owner" cols="12" md="6">
                <v-card class="h-100" title="Il tuo referente">
                    <v-card-text>
                        {{ quote.owner.name }}<br />
                        <a :href="`mailto:${quote.owner.email}`">{{ quote.owner.email }}</a>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col v-if="quote.terms" cols="12">
                <v-card title="Condizioni">
                    <v-card-text class="text-pre-line">{{ quote.terms }}</v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card v-if="quote.is_open" color="primary" variant="tonal" class="mb-6">
            <v-card-text class="d-flex flex-wrap align-center ga-3">
                <div class="flex-grow-1">
                    <div class="text-subtitle-1 font-weight-bold">Vuoi procedere?</div>
                    <div class="text-body-2">Accetta il preventivo online: riceveremo subito la tua conferma.</div>
                </div>
                <v-btn variant="text" @click="rejectDialog = true">Rifiuta</v-btn>
                <v-btn color="primary" variant="flat" size="large" prepend-icon="mdi-check" @click="acceptDialog = true">
                    Accetta {{ formatMoney(totals.total) }}
                </v-btn>
            </v-card-text>
        </v-card>

        <div class="text-center text-caption text-medium-emphasis">
            {{ company.name }}<template v-if="company.vat_number"> · P.IVA {{ company.vat_number }}</template>
            <template v-if="company.email"> · {{ company.email }}</template>
            <template v-if="company.phone"> · {{ company.phone }}</template>
        </div>

        <v-dialog v-model="acceptDialog" max-width="520">
            <v-card title="Accetta il preventivo">
                <v-form @submit.prevent="accept">
                    <v-card-text>
                        <p class="mb-4">
                            Totale <strong>{{ formatMoney(totals.total) }}</strong> IVA inclusa
                            <template v-if="hasOptional">, con le voci facoltative selezionate</template>.
                        </p>
                        <v-text-field v-model="acceptForm.name" label="Nome e cognome" autocomplete="name" :error-messages="acceptForm.errors.name" autofocus />
                        <v-textarea v-model="acceptForm.note" label="Note (facoltative)" rows="2" auto-grow :error-messages="acceptForm.errors.note" />
                        <v-checkbox
                            v-model="acceptForm.agree"
                            label="Accetto il preventivo e le condizioni indicate"
                            :error-messages="acceptForm.errors.agree"
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="acceptDialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" :loading="acceptForm.processing">Conferma accettazione</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>

        <v-dialog v-model="rejectDialog" max-width="520">
            <v-card title="Rifiuta il preventivo">
                <v-form @submit.prevent="reject">
                    <v-card-text>
                        <v-text-field v-model="rejectForm.name" label="Nome e cognome (facoltativo)" :error-messages="rejectForm.errors.name" />
                        <v-textarea
                            v-model="rejectForm.note"
                            label="Motivo (facoltativo)"
                            hint="Ci aiuta a migliorare la nostra offerta"
                            persistent-hint
                            rows="3"
                            auto-grow
                            :error-messages="rejectForm.errors.note"
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="rejectDialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="error" variant="flat" :loading="rejectForm.processing">Rifiuta</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </div>
</template>
