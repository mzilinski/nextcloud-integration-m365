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
			<NcButton type="primary" :disabled="busy" @click="connect">
				{{ t('integration_m365', 'Connect to Microsoft 365') }}
			</NcButton>
		</template>
		<template v-else>
			<p>
				{{ t('integration_m365', 'Connected as {name} ({principal})', { name: state.account.name, principal: state.account.principal }) }}
			</p>
			<NcNoteCard v-if="state.account.reauthRequired" type="error">
				{{ t('integration_m365', 'Microsoft rejected the stored login. Please connect again.') }}
				<NcButton @click="connect">{{ t('integration_m365', 'Connect again') }}</NcButton>
			</NcNoteCard>
			<div class="actions">
				<NcButton :disabled="busy || syncing" @click="syncNow">
					<template v-if="syncing" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ syncing ? syncStatusText : t('integration_m365', 'Sync now') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="busy" @click="disconnect">{{ t('integration_m365', 'Disconnect') }}</NcButton>
			</div>
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
			<NcNoteCard v-else-if="pendingCount > 0 && !syncing" type="warning">
				{{ n('integration_m365', '{count} collection is enabled but has not been synchronised yet.', '{count} collections are enabled but have not been synchronised yet.', pendingCount, { count: pendingCount }) }}
				{{ t('integration_m365', 'The background job picks it up within 5 minutes, or start it right away:') }}
				<NcButton type="primary" @click="syncNow">{{ t('integration_m365', 'Sync now') }}</NcButton>
			</NcNoteCard>
			<CollectionTable v-for="type in state.types" :key="type"
				:type="type"
				:rows="collections[type] || []"
				:loading="loading"
				@change="saveRow" />
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import CollectionTable from './CollectionTable.vue'

export default {
	name: 'PersonalSettings',
	components: { NcButton, NcNoteCard, NcLoadingIcon, CollectionTable },
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
		}
	},
	beforeUnmount() {
		clearTimeout(this.pollTimer)
	},
	computed: {
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
	},
	mounted() {
		const params = new URLSearchParams(window.location.search)
		if (params.get('m365') === 'connected') {
			showSuccess(this.t('integration_m365', 'Microsoft account connected'))
		} else if (params.get('m365') === 'error') {
			showError(this.t('integration_m365', 'Connecting failed: {message}', { message: params.get('message') || '' }))
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
		async disconnect() {
			this.busy = true
			try {
				await axios.post(generateUrl('/apps/integration_m365/disconnect'), { purge: false })
				this.state.connected = false
				this.state.account = null
				this.collections = {}
			} catch (e) {
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
			} catch (e) {
				this.syncing = false
				showError(this.t('integration_m365', 'Could not read the synchronisation status'))
				return
			}
			// mirror lastSync/lastError into the tables as they come in
			let allDone = status.pairs.length > 0
			const errors = []
			for (const p of status.pairs) {
				const row = (this.collections[p.type] || []).find(r => r.remoteId === p.remoteId)
				if (row) {
					row.lastSync = p.lastSync
					row.lastError = p.lastError
				}
				const touched = p.lastSync !== before[p.remoteId] || (p.lastError && !p.running)
				if (!touched || p.running) {
					allDone = false
				}
				if (p.lastError && !p.running) {
					errors.push(p.lastError)
				}
			}
			if (status.running) {
				this.syncStatusText = this.t('integration_m365', 'Synchronising…')
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
				this.error = e.response?.data?.error || this.t('integration_m365', 'Could not load your Microsoft 365 collections')
				if (e.response?.data?.reauthRequired) {
					this.state.account.reauthRequired = true
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
				showError(this.t('integration_m365', 'Could not save the collection settings'))
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
