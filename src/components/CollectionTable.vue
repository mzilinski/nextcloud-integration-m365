<!--
SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="m365-table">
		<h3>{{ title }}</h3>
		<p v-if="!loading && rows.length === 0" class="settings-hint">
			{{ t('integration_m365', 'Nothing found in your Microsoft account.') }}
		</p>
		<table v-else>
			<thead>
				<tr>
					<th>{{ t('integration_m365', 'Sync') }}</th>
					<th>{{ t('integration_m365', 'Microsoft 365') }}</th>
					<th>{{ t('integration_m365', 'Nextcloud name') }}</th>
					<th>{{ t('integration_m365', 'On conflict') }}</th>
					<th>{{ t('integration_m365', 'Last sync') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.remoteId">
					<td>
						<NcCheckboxRadioSwitch :model-value="row.enabled"
							@update:model-value="v => update(row, 'enabled', v)" />
					</td>
					<td>
						{{ row.remoteName }}
						<span v-if="row.readOnly" class="muted">({{ t('integration_m365', 'read-only') }})</span>
					</td>
					<td>
						<NcTextField :model-value="row.localName"
							@update:model-value="v => row.localName = v"
							@blur="update(row, 'localName', row.localName)" />
					</td>
					<td>
						<span v-if="row.readOnly" class="muted" :title="t('integration_m365', 'Microsoft 365 does not allow changes to this collection; it is copied to Nextcloud only.')">
							{{ t('integration_m365', 'Import only') }}
						</span>
						<select v-else :value="row.conflictPolicy" @change="update(row, 'conflictPolicy', $event.target.value)">
							<option value="remote">{{ t('integration_m365', 'Microsoft 365 wins') }}</option>
							<option value="local">{{ t('integration_m365', 'Nextcloud wins') }}</option>
						</select>
					</td>
					<td>
						<span v-if="row.lastError" class="error" :title="row.lastError">⚠ {{ row.lastError }}</span>
						<span v-else-if="row.lastSync">{{ new Date(row.lastSync * 1000).toLocaleString() }}</span>
						<span v-else-if="row.enabled" class="pending" :title="t('integration_m365', 'Enabled, but not synchronised yet. Click “Sync now” or wait for the next background run (every 5 minutes).')">
							⏳ {{ t('integration_m365', 'Pending') }}
						</span>
						<span v-else class="muted">—</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'

export default {
	name: 'CollectionTable',
	components: { NcCheckboxRadioSwitch, NcTextField },
	props: {
		type: { type: String, required: true },
		rows: { type: Array, required: true },
		loading: { type: Boolean, default: false },
	},
	emits: ['change'],
	computed: {
		title() {
			return {
				calendar: this.t('integration_m365', 'Calendars'),
				contacts: this.t('integration_m365', 'Contact folders'),
				tasks: this.t('integration_m365', 'To Do lists'),
			}[this.type] || this.type
		},
	},
	methods: {
		update(row, key, value) {
			row[key] = value
			this.$emit('change', { ...row, type: this.type })
		},
	},
}
</script>

<style scoped lang="scss">
.m365-table {
	margin-top: 16px;
	table { width: 100%; max-width: 1000px; border-collapse: collapse; }
	th, td { text-align: left; padding: 4px 8px; vertical-align: middle; }
	.muted { color: var(--color-text-maxcontrast); }
	.error { color: var(--color-error); }
	.pending { color: var(--color-warning-text, var(--color-warning)); font-weight: bold; }
}
</style>
