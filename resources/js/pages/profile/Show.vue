<script setup>
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import AvatarForm from './partials/AvatarForm.vue';
import BrowserSessions from './partials/BrowserSessions.vue';
import DeleteAccount from './partials/DeleteAccount.vue';
import NotificationPreferencesForm from './partials/NotificationPreferencesForm.vue';
import PushNotificationsCard from './partials/PushNotificationsCard.vue';
import PasswordForm from './partials/PasswordForm.vue';
import ProfileInformationForm from './partials/ProfileInformationForm.vue';
import TwoFactorAuthentication from './partials/TwoFactorAuthentication.vue';

const props = defineProps({
    user: { type: Object, required: true },
    tab: { type: String, default: 'profile' },
    twoFactor: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
});

const tabs = [
    { value: 'profile', title: 'Profilo', icon: 'mdi-account-outline' },
    { value: 'security', title: 'Sicurezza', icon: 'mdi-shield-lock-outline' },
    { value: 'notifications', title: 'Notifiche', icon: 'mdi-bell-cog-outline' },
    { value: 'sessions', title: 'Sessioni', icon: 'mdi-devices' },
    { value: 'account', title: 'Account', icon: 'mdi-account-cog-outline' },
];

const current = ref(tabs.some((item) => item.value === props.tab) ? props.tab : 'profile');

// Keep the tab in the URL (shareable, survives reloads) without an extra request.
watch(current, (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
});

watch(
    () => props.tab,
    (value) => {
        if (tabs.some((item) => item.value === value)) {
            current.value = value;
        }
    },
);
</script>

<template>
    <PageHeader title="Il mio profilo" subtitle="Gestisci le informazioni personali, la sicurezza e le preferenze del tuo account." />

    <v-card class="mb-6">
        <v-card-item class="py-4">
            <template #prepend>
                <UserAvatar :user="user" size="64" class="mr-2" />
            </template>
            <v-card-title class="text-h6 font-weight-bold">{{ user.name }}</v-card-title>
            <v-card-subtitle>{{ user.email }}</v-card-subtitle>
            <div class="d-flex flex-wrap ga-1 mt-2">
                <v-chip v-for="role in user.role_labels" :key="role" color="secondary" variant="tonal">{{ role }}</v-chip>
            </div>
        </v-card-item>
        <v-tabs v-model="current" color="primary" show-arrows>
            <v-tab v-for="item in tabs" :key="item.value" :value="item.value" :prepend-icon="item.icon">{{ item.title }}</v-tab>
        </v-tabs>
    </v-card>

    <v-tabs-window v-model="current">
        <v-tabs-window-item value="profile">
            <div class="d-flex flex-column ga-6">
                <AvatarForm :user="user" />
                <ProfileInformationForm :user="user" />
            </div>
        </v-tabs-window-item>

        <v-tabs-window-item value="security">
            <div class="d-flex flex-column ga-6">
                <PasswordForm />
                <TwoFactorAuthentication :two-factor="twoFactor" />
            </div>
        </v-tabs-window-item>

        <v-tabs-window-item value="notifications">
            <div class="d-flex flex-column ga-6">
                <PushNotificationsCard />
                <NotificationPreferencesForm :user="user" />
            </div>
        </v-tabs-window-item>

        <v-tabs-window-item value="sessions">
            <BrowserSessions :sessions="sessions" />
        </v-tabs-window-item>

        <v-tabs-window-item value="account">
            <DeleteAccount />
        </v-tabs-window-item>
    </v-tabs-window>
</template>
