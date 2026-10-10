<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="m365_admin" class="section">
		<h2>{{ t('integration_m365', 'Microsoft 365 integration') }}</h2>
		<p class="settings-hint">
			{{ t('integration_m365', 'Register an application in Microsoft Entra ID (Azure portal → App registrations), add the redirect URI below under "Web", create a client secret and grant the delegated Microsoft Graph permissions Calendars.ReadWrite, Contacts.ReadWrite, Tasks.ReadWrite, User.Read and offline_access.') }}
		</p>
		<div class="field">
			<NcTextField :value="state.redirect_uri"
				:label="t('integration_m365', 'Redirect URI (copy into the Azure app registration)')"
				readonly
				@focus="$event.target.select()" />
		</div>
		<div class="field">
			<NcTextField v-model="state.client_id"
				:label="t('integration_m365', 'Application (client) ID')"
				placeholder="00000000-0000-0000-0000-000000000000" />
		</div>
		<div class="field">
			<NcPasswordField v-model="state.client_secret"
				:label="t('integration_m365', 'Client secret')" />
		</div>
		<div class="field">
			<NcTextField v-model="state.tenant"
				:label="t('integration_m365', 'Tenant')"
				:helper-text="t('integration_m365', '\'common\' (any Microsoft account), \'organizations\', \'consumers\' or your tenant ID')" />
		</div>
		<NcButton type="primary" :disabled="saving" @click="save">
			{{ saving ? t('integration_m365', 'Saving…') : t('integration_m365', 'Save') }}
		</NcButton>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { NcButton, NcTextField, NcPasswordField } from '@nextcloud/vue'

export default {
	name: 'AdminSettings',
	components: { NcButton, NcTextField, NcPasswordField },
	data() {
		return {
			state: loadState('integration_m365', 'admin-config'),
			saving: false,
		}
	},
	methods: {
		async save() {
			this.saving = true
			try {
				await confirmPassword()
				await axios.put(generateUrl('/apps/integration_m365/admin-config'), {
					values: {
						client_id: this.state.client_id,
						client_secret: this.state.client_secret,
						tenant: this.state.tenant,
					},
				})
				showSuccess(this.t('integration_m365', 'Microsoft 365 settings saved'))
			} catch (e) {
				console.error(e)
				showError(this.t('integration_m365', 'Failed to save settings'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
#m365_admin {
	.field { max-width: 600px; margin-bottom: 12px; }
}
</style>
