<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="m365_admin" class="section">
		<h2>{{ t('integration_m365', 'Microsoft 365 integration') }}</h2>
		<p class="settings-hint">
			{{ t('integration_m365', 'Register an application in Microsoft Entra ID (App registrations), add the redirect URI below under "Authentication → Web", create a client secret and grant the delegated Microsoft Graph permissions {scopes}.', { scopes: state.scopes }) }}
			<a :href="docsUrl" target="_blank" rel="noopener noreferrer">{{ t('integration_m365', 'Step-by-step guide') }} ↗</a>
		</p>
		<div class="field">
			<NcTextField
				:modelValue="state.redirect_uri"
				:label="t('integration_m365', 'Redirect URI (copy into the Entra ID app registration)')"
				readonly
				showTrailingButton
				:trailingButtonLabel="t('integration_m365', 'Copy redirect URI')"
				@trailingButtonClick="copyRedirect"
				@focus="$event.target.select()">
				<template #trailing-button-icon>
					<svg
						width="20"
						height="20"
						viewBox="0 0 24 24"
						aria-hidden="true"><path fill="currentColor" d="M19 21H8V7h11m0-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2m-3-4H4a2 2 0 0 0-2 2v14h2V3h12V1z" /></svg>
				</template>
			</NcTextField>
		</div>
		<div class="field">
			<NcTextField
				v-model="state.client_id"
				:label="t('integration_m365', 'Application (client) ID')"
				placeholder="00000000-0000-0000-0000-000000000000" />
		</div>
		<div class="field">
			<NcPasswordField
				v-model="state.client_secret"
				:label="t('integration_m365', 'Client secret (value, not ID)')" />
		</div>
		<div class="field">
			<NcDateTimePickerNative
				id="m365_secret_expires"
				v-model="secretExpires"
				type="date"
				:label="t('integration_m365', 'Client secret expires on (optional)')" />
			<NcNoteCard v-if="secretHint" :type="secretHintType">
				{{ secretHint }}
			</NcNoteCard>
			<p v-else class="settings-hint">
				{{ t('integration_m365', 'Entra ID client secrets expire after 24 months at most. Note the date here to get a reminder before every user loses the connection.') }}
			</p>
		</div>
		<div class="field">
			<NcTextField
				v-model="state.tenant"
				:label="t('integration_m365', 'Tenant')"
				:helperText="t('integration_m365', 'Directory (tenant) ID for a single-tenant registration, otherwise \'common\' (any Microsoft account), \'organizations\' or \'consumers\'')" />
		</div>
		<div class="actions">
			<NcButton variant="primary" :disabled="saving || testing" @click="save">
				{{ saving ? t('integration_m365', 'Saving…') : t('integration_m365', 'Save') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="saving || testing" @click="test">
				<template v-if="testing" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('integration_m365', 'Test connection') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="testResult" :type="testResult.ok ? 'success' : 'error'">
			{{ testResult.message }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDateTimePickerNative, NcLoadingIcon, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AdminSettings',
	components: { NcButton, NcTextField, NcPasswordField, NcNoteCard, NcLoadingIcon, NcDateTimePickerNative },
	data() {
		const state = loadState('integration_m365', 'admin-config')
		return {
			state,
			secretExpires: state.secret_expires ? new Date(state.secret_expires + 'T12:00:00') : null,
			saving: false,
			testing: false,
			testResult: null,
			docsUrl: 'https://github.com/mzilinski/nextcloud-integration-m365/blob/main/docs/admin.md',
		}
	},

	computed: {
		secretDaysLeft() {
			if (!this.secretExpires) {
				return null
			}
			return Math.floor((this.secretExpires.getTime() - Date.now()) / 86400000)
		},

		secretHint() {
			const d = this.secretDaysLeft
			if (d === null || d > 30) {
				return ''
			}
			if (d < 0) {
				return this.t('integration_m365', 'The client secret has expired. Create a new one in Entra ID and save it here; users stay connected.')
			}
			return this.n('integration_m365', 'The client secret expires in {count} day. Create a new one in Entra ID and save it here before that.', 'The client secret expires in {count} days. Create a new one in Entra ID and save it here before that.', d, { count: d })
		},

		secretHintType() {
			return this.secretDaysLeft < 0 ? 'error' : 'warning'
		},
	},

	methods: {
		isoDate(date) {
			if (!date) {
				return ''
			}
			const pad = (v) => String(v).padStart(2, '0')
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
		},

		async copyRedirect() {
			try {
				await navigator.clipboard.writeText(this.state.redirect_uri)
				showSuccess(this.t('integration_m365', 'Redirect URI copied'))
			} catch {
				showError(this.t('integration_m365', 'Could not copy, select the field and copy it manually'))
			}
		},

		async save() {
			this.saving = true
			this.testResult = null
			try {
				await confirmPassword()
				const { data } = await axios.put(generateUrl('/apps/integration_m365/admin-config'), {
					values: {
						client_id: this.state.client_id,
						client_secret: this.state.client_secret,
						tenant: this.state.tenant,
						secret_expires: this.isoDate(this.secretExpires),
					},
				})
				if (data.invalidatedUsers > 0) {
					showSuccess(this.n('integration_m365', 'Settings saved. {count} connected user has been asked to connect again because the registration changed.', 'Settings saved. {count} connected users have been asked to connect again because the registration changed.', data.invalidatedUsers, { count: data.invalidatedUsers }))
				} else {
					showSuccess(this.t('integration_m365', 'Microsoft 365 settings saved'))
				}
			} catch (e) {
				showError(e.response?.data?.error
					? this.t('integration_m365', 'Failed to save settings: {error}', { error: e.response.data.error })
					: this.t('integration_m365', 'Failed to save settings'))
			} finally {
				this.saving = false
			}
		},

		async test() {
			this.testing = true
			this.testResult = null
			try {
				this.testResult = (await axios.post(generateUrl('/apps/integration_m365/admin-config/test'))).data
			} catch {
				this.testResult = { ok: false, message: this.t('integration_m365', 'The test request failed, see the Nextcloud log') }
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
#m365_admin {
	.field { max-width: 600px; margin-bottom: 12px; }
	.actions { display: flex; gap: 8px; margin: 12px 0; }
}
</style>
