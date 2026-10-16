<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="m365_personal" class="section">
		<h2>{{ t('integration_m365', 'Microsoft 365 integration') }}</h2>
		<NcNoteCard v-if="!state.configured" type="warning">
			{{ t('integration_m365', 'The administrator has not configured the Microsoft 365 integration yet.') }}
		</NcNoteCard>
		<template v-else-if="!state.connected">
			<p class="settings-hint">
				{{ t('integration_m365', 'Connect your Microsoft account to synchronise calendars, contacts and To Do lists with Nextcloud in both directions.') }}
			</p>
			<NcButton variant="primary" :disabled="busy" @click="connect">
				{{ t('integration_m365', 'Connect to Microsoft 365') }}
			</NcButton>
		</template>
		<template v-else>
			<p>
				{{ t('integration_m365', 'Connected as {name} ({principal})', { name: state.account.name, principal: state.account.principal }) }}
			</p>
			<NcNoteCard v-if="state.account.reauthRequired" type="error">
				{{ t('integration_m365', 'Microsoft rejected the stored login (for example after a password change). Please connect again.') }}
				<NcButton variant="primary" :disabled="busy" @click="connect">
					{{ t('integration_m365', 'Connect again') }}
				</NcButton>
			</NcNoteCard>
			<div class="actions">
				<NcButton :disabled="busy || syncing" @click="syncNow">
					<template v-if="syncing" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ syncing ? syncStatusText : t('integration_m365', 'Sync now') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="showDisconnect = true">
					{{ t('integration_m365', 'Disconnect') }}
				</NcButton>
			</div>
			<NcDialog
				:open="showDisconnect"
				:name="t('integration_m365', 'Disconnect Microsoft account')"
				:message="t('integration_m365', 'Synchronisation stops. Calendars, contacts and tasks already in Nextcloud stay. Keep the collection settings to resume later, or forget them together with the synchronisation state.')"
				:buttons="disconnectButtons"
				@update:open="v => showDisconnect = v" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-else-if="(pendingCount > 0 || remainingCount > 0) && !syncing" type="warning">
				<span v-if="pendingCount > 0">
					{{ n('integration_m365', '{count} collection is enabled but has not been synchronised yet.', '{count} collections are enabled but have not been synchronised yet.', pendingCount, { count: pendingCount }) }}
				</span>
				<span v-if="remainingCount > 0">
					{{ n('integration_m365', 'Import in progress: {count} object still to be copied.', 'Import in progress: {count} objects still to be copied.', remainingCount, { count: remainingCount }) }}
				</span>
				{{ t('integration_m365', 'It continues automatically in the background, or start the next round right away:') }}
				<NcButton variant="primary" :disabled="busy || syncing" @click="syncNow">
					{{ t('integration_m365', 'Sync now') }}
				</NcButton>
			</NcNoteCard>
			<CollectionTable
				v-for="type in state.types"
				:key="type"
				:type="type"
				:rows="collections[type] || []"
				:loading="loading"
				@change="saveRow" />
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import CollectionTable from './CollectionTable.vue'
import { describeSyncError } from '../errors.js'

export default {
	name: 'PersonalSettings',
	components: { NcButton, NcNoteCard, NcLoadingIcon, NcDialog, CollectionTable },
	data() {
		return {
			state: loadState('integration_m365', 'user-config'),
			collections: {},
			loading: false,
			busy: false,
			error: '',
			syncing: false,
			syncStatusText: '',
			pollTimer: null,
			showDisconnect: false,
		}
	},

	computed: {
		disconnectButtons() {
			return [
				{ label: this.t('integration_m365', 'Cancel'), callback: () => { this.showDisconnect = false } },
				{ label: this.t('integration_m365', 'Disconnect, keep settings'), variant: 'secondary', callback: () => this.disconnect(false) },
				{ label: this.t('integration_m365', 'Disconnect and forget sync state'), variant: 'error', callback: () => this.disconnect(true) },
			]
		},

		pendingCount() {
			let n = 0
			for (const type of Object.keys(this.collections)) {
				for (const row of this.collections[type]) {
					if (row.enabled && !row.lastSync && !row.lastError) {
						n++
					}
				}
			}
			return n
		},

		remainingCount() {
			let n = 0
			for (const type of Object.keys(this.collections)) {
				for (const row of this.collections[type]) {
					n += row.enabled && row.pending > 0 ? row.pending : 0
				}
			}
			return n
		},
	},

	beforeUnmount() {
		clearTimeout(this.pollTimer)
	},

	mounted() {
		const params = new URLSearchParams(window.location.search)
		if (params.get('m365') === 'connected') {
			showSuccess(this.t('integration_m365', 'Microsoft account connected'))
		} else if (params.get('m365') === 'error') {
			// the message was translated and mapped (AADSTS codes) by the server
			this.error = this.t('integration_m365', 'Connecting failed: {message}', { message: params.get('message') || '' })
			showError(this.error)
		}
		if (params.has('m365')) {
			// a reload must not repeat the toast, and the error text does not belong in the address bar
			params.delete('m365')
			params.delete('message')
			const query = params.toString()
			window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash)
		}
		if (this.state.connected) {
			this.loadCollections()
		}
	},

	methods: {
		async connect() {
			this.busy = true
			try {
				const { data } = await axios.get(generateUrl('/apps/integration_m365/oauth-url'))
				window.location.replace(data.url)
			} catch (e) {
				showError(e.response?.data?.error || this.t('integration_m365', 'Could not start the login'))
				this.busy = false
			}
		},

		async disconnect(purge) {
			this.showDisconnect = false
			this.busy = true
			try {
				await axios.post(generateUrl('/apps/integration_m365/disconnect'), { purge })
				this.state.connected = false
				this.state.account = null
				this.collections = {}
				this.error = ''
				showSuccess(purge
					? this.t('integration_m365', 'Microsoft account disconnected, synchronisation state forgotten')
					: this.t('integration_m365', 'Microsoft account disconnected'))
			} catch {
				showError(this.t('integration_m365', 'Disconnecting failed'))
			} finally {
				this.busy = false
			}
		},

		async syncNow() {
			this.syncing = true
			this.syncStatusText = this.t('integration_m365', 'Synchronising…')
			const before = {}
			for (const type of Object.keys(this.collections)) {
				for (const row of this.collections[type]) {
					before[row.remoteId] = row.lastSync
				}
			}
			let data
			try {
				data = (await axios.post(generateUrl('/apps/integration_m365/sync-now'), { inline: true }, { timeout: 120000 })).data
			} catch (e) {
				if (e.response) {
					// the server answered: a real failure, not something the background job would fix
					this.syncing = false
					if (e.response.data?.error === 'reauth_required') {
						this.state.account.reauthRequired = true
					}
					showError(describeSyncError(e.response.data?.error || 'failed'))
					return
				}
				// gateway timeout or similar: the server keeps working, fall back to polling
				data = { complete: false, queued: true }
			}
			if (data.complete) {
				// one status read to refresh "Last sync" / errors in the tables, then report
				this.pollStatus(before, Date.now(), true)
				return
			}
			this.syncStatusText = this.t('integration_m365', 'Continuing in the background…')
			this.pollStatus(before, Date.now())
		},

		async pollStatus(before, startedAt, once = false) {
			let status
			try {
				status = (await axios.get(generateUrl('/apps/integration_m365/sync-status'))).data
			} catch {
				this.syncing = false
				showError(this.t('integration_m365', 'Could not read the synchronisation status'))
				return
			}
			// mirror lastSync/lastError into the tables as they come in
			let allDone = status.pairs.length > 0
			const errors = []
			for (const p of status.pairs) {
				const row = (this.collections[p.type] || []).find((r) => r.remoteId === p.remoteId)
				if (row) {
					row.lastSync = p.lastSync
					row.lastError = p.lastError
					row.pending = p.pending
				}
				const touched = p.lastSync !== before[p.remoteId] || (p.lastError && !p.running)
				if (!touched || p.running) {
					allDone = false
				}
				if (p.lastError && !p.running && !errors.includes(describeSyncError(p.lastError))) {
					errors.push(describeSyncError(p.lastError))
				}
			}
			if (status.running) {
				this.syncStatusText = this.t('integration_m365', 'Synchronising…')
			}
			const remaining = status.pairs.reduce((sum, p) => sum + (p.pending || 0), 0)
			if (remaining > 0 && (status.queued || status.running)) {
				// a large import continues in rounds: keep watching, show progress
				this.syncStatusText = this.n('integration_m365', 'Importing, {count} object remaining…', 'Importing, {count} objects remaining…', remaining, { count: remaining })
				allDone = false
			}
			const finished = once || (!status.queued && !status.running && allDone)
			const timedOut = Date.now() - startedAt > 15 * 60 * 1000
			if (finished || timedOut || status.pairs.length === 0) {
				this.syncing = false
				if (status.pairs.length === 0) {
					showError(this.t('integration_m365', 'Nothing to synchronise: enable at least one collection below'))
				} else if (timedOut) {
					showError(this.t('integration_m365', 'The background job did not run within 15 minutes. Is the Nextcloud cron set up?'))
				} else if (errors.length) {
					showError(this.t('integration_m365', 'Synchronisation finished with errors: {errors}', { errors: errors.join('; ') }))
				} else {
					showSuccess(this.t('integration_m365', 'Synchronisation finished'))
				}
				return
			}
			this.pollTimer = setTimeout(() => this.pollStatus(before, startedAt), 3000)
		},

		async loadCollections() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/integration_m365/collections'))
				this.collections = data
			} catch (e) {
				if (e.response?.data?.reauthRequired) {
					this.state.account.reauthRequired = true
					this.error = describeSyncError('reauth_required')
				} else {
					this.error = e.response?.data?.error
						? this.t('integration_m365', 'Could not load your Microsoft 365 collections: {message}', { message: e.response.data.error })
						: this.t('integration_m365', 'Could not load your Microsoft 365 collections')
				}
			} finally {
				this.loading = false
			}
		},

		async saveRow(row) {
			try {
				const { data } = await axios.put(generateUrl('/apps/integration_m365/collections/{type}', { type: row.type }), {
					remoteId: row.remoteId,
					remoteName: row.remoteName,
					enabled: row.enabled,
					localName: row.localName,
					conflictPolicy: row.conflictPolicy,
				})
				row.id = data.id
			} catch (e) {
				if (e.response?.data?.reauthRequired) {
					this.state.account.reauthRequired = true
				}
				showError(e.response?.data?.error
					? this.t('integration_m365', 'Could not save the collection settings: {message}', { message: e.response.data.error })
					: this.t('integration_m365', 'Could not save the collection settings'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
#m365_personal {
	.actions { display: flex; gap: 8px; margin: 12px 0; }
}
</style>
