<script setup>
/**
 * Role name + permissions grouped by area, shared by the create / edit role pages.
 */
const form = defineModel('form', { type: Object, required: true });

defineProps({
    permissionGroups: { type: Array, required: true },
    /** System roles can't be renamed. */
    lockName: { type: Boolean, default: false },
});

function groupState(group) {
    const names = group.permissions.map((permission) => permission.name);
    const selected = names.filter((name) => form.value.permissions.includes(name)).length;

    return { all: selected === names.length, some: selected > 0 && selected < names.length };
}

function toggleGroup(group, value) {
    const names = group.permissions.map((permission) => permission.name);
    const others = form.value.permissions.filter((name) => !names.includes(name));

    form.value.permissions = value ? [...others, ...names] : others;
}
</script>

<template>
    <v-text-field
        v-model="form.name"
        label="Nome del ruolo"
        :disabled="lockName"
        :hint="lockName ? 'I ruoli di sistema non possono essere rinominati.' : 'Lettere minuscole, numeri e trattini, es. editor-contenuti.'"
        persistent-hint
        :error-messages="form.errors.name"
        class="mb-6"
    />

    <div class="text-subtitle-1 font-weight-medium mb-2">Permessi</div>
    <div v-if="form.errors.permissions" class="text-error text-body-2 mb-2">{{ form.errors.permissions }}</div>

    <v-row>
        <v-col v-for="group in permissionGroups" :key="group.group" cols="12" md="6" lg="4">
            <v-card variant="outlined" class="h-100">
                <v-card-item class="pb-0">
                    <v-checkbox
                        :model-value="groupState(group).all"
                        :indeterminate="groupState(group).some"
                        :label="group.group"
                        class="font-weight-bold"
                        @update:model-value="(value) => toggleGroup(group, value)"
                    />
                </v-card-item>
                <v-divider />
                <v-card-text class="pt-2">
                    <v-checkbox
                        v-for="permission in group.permissions"
                        :key="permission.name"
                        v-model="form.permissions"
                        :value="permission.name"
                        :label="permission.label"
                        :hint="permission.name"
                        persistent-hint
                        class="mb-1"
                    />
                </v-card-text>
            </v-card>
        </v-col>
    </v-row>
</template>
