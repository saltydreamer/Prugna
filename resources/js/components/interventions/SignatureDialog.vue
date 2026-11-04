<script setup>
import { nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

/**
 * Customer signature drawn with the finger (or mouse) on the technician's device.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    /** Without an intervention (offline form) the signature is emitted instead of being sent. */
    interventionId: { type: Number, default: null },
    defaultName: { type: String, default: '' },
});

const emit = defineEmits(['signed']);

const canvas = ref(null);
const name = ref('');
const empty = ref(true);
const saving = ref(false);
const errors = ref({});

let context = null;
let drawing = false;
let last = null;

watch(open, async (value) => {
    if (!value) {
        return;
    }

    name.value = props.defaultName;
    errors.value = {};
    await nextTick();
    // The dialog animates in: wait for the final size.
    setTimeout(setup, 250);
});

function setup() {
    const element = canvas.value;

    if (!element) {
        return;
    }

    const ratio = Math.max(window.devicePixelRatio || 1, 2);
    const { width, height } = element.getBoundingClientRect();
    element.width = Math.round(width * ratio);
    element.height = Math.round(height * ratio);
    context = element.getContext('2d');
    context.scale(ratio, ratio);
    context.lineWidth = 2.2;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#1a237e';
    empty.value = true;
}

function point(event) {
    const rect = canvas.value.getBoundingClientRect();

    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
}

function start(event) {
    if (!context) {
        return;
    }

    event.preventDefault();
    canvas.value.setPointerCapture?.(event.pointerId);
    drawing = true;
    last = point(event);
    context.beginPath();
    context.arc(last.x, last.y, 1, 0, Math.PI * 2);
    context.fillStyle = context.strokeStyle;
    context.fill();
    empty.value = false;
}

function move(event) {
    if (!drawing) {
        return;
    }

    event.preventDefault();
    const current = point(event);
    context.beginPath();
    context.moveTo(last.x, last.y);
    context.lineTo(current.x, current.y);
    context.stroke();
    last = current;
}

function end() {
    drawing = false;
}

function clear() {
    if (!context) {
        return;
    }

    context.save();
    context.setTransform(1, 0, 0, 1, 0, 0);
    context.clearRect(0, 0, canvas.value.width, canvas.value.height);
    context.restore();
    empty.value = true;
}

function save() {
    if (!props.interventionId) {
        emit('signed', { name: name.value.trim(), data: canvas.value.toDataURL('image/png') });
        open.value = false;

        return;
    }

    saving.value = true;
    router.post(
        route('interventions.sign', props.interventionId),
        { signed_by_name: name.value, signature: canvas.value.toDataURL('image/png') },
        {
            preserveScroll: true,
            onError: (bag) => (errors.value = bag),
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <v-dialog v-model="open" max-width="640" :fullscreen="$vuetify.display.xs" persistent>
        <v-card title="Firma del cliente" subtitle="Dopo la firma l'intervento non si può più modificare.">
            <v-card-text>
                <v-text-field v-model="name" label="Nome e cognome di chi firma" :error-messages="errors.signed_by_name" class="mb-3" />
                <div class="signature-pad">
                    <canvas ref="canvas" @pointerdown="start" @pointermove="move" @pointerup="end" @pointercancel="end" @pointerleave="end" />
                    <span v-if="empty" class="signature-pad__hint">Firma qui con il dito</span>
                </div>
                <div v-if="errors.signature" class="text-error text-caption mt-1">{{ errors.signature }}</div>
            </v-card-text>
            <v-card-actions>
                <v-btn variant="text" prepend-icon="mdi-eraser" :disabled="empty" @click="clear">Cancella</v-btn>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-draw" :disabled="empty || !name.trim()" :loading="saving" @click="save">
                    Conferma firma
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.signature-pad {
    position: relative;
    border: 1px dashed rgba(var(--v-theme-on-surface), 0.3);
    border-radius: 8px;
    background: #fff;
    height: 220px;
}

.signature-pad canvas {
    width: 100%;
    height: 100%;
    display: block;
    touch-action: none;
    cursor: crosshair;
}

.signature-pad__hint {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9e9e9e;
    pointer-events: none;
}
</style>
