// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { translatePlural as n, translate as t } from '@nextcloud/l10n'

/**
 * Human-readable text for a `last_error` value (see lib/Sync/SyncError.php).
 * Unknown values are raw exception messages meant for the admin and are shown shortened.
 *
 * @param {string|null} code the stored error
 * @return {string}
 */
export function describeSyncError(code) {
	if (!code) {
		return ''
	}
	if (code === 'reauth_required') {
		return t('integration_m365', 'Microsoft rejected the stored login: connect your account again')
	}
	if (code === 'config_error') {
		return t('integration_m365', 'Entra ID rejects the app registration: the administrator has to check client ID, secret and tenant')
	}
	if (code === 'local_missing') {
		return t('integration_m365', 'The Nextcloud calendar or address book was deleted. Enable the collection again to start over.')
	}
	if (code === 'failed') {
		return t('integration_m365', 'The synchronisation failed, see the Nextcloud log')
	}
	if (code.startsWith('items_failed:')) {
		const count = parseInt(code.slice('items_failed:'.length), 10) || 1
		return n('integration_m365', '{count} object could not be synchronised, see the Nextcloud log', '{count} objects could not be synchronised, see the Nextcloud log', count, { count })
	}
	const short = code.length > 160 ? code.slice(0, 157) + '…' : code
	return t('integration_m365', 'Error: {message}', { message: short })
}
