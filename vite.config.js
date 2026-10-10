// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createAppConfig } from '@nextcloud/vite-config'
import { join } from 'path'

const isProduction = process.env.NODE_ENV === 'production'
export default createAppConfig({
	adminSettings: join('src', 'adminSettings.js'),
	personalSettings: join('src', 'personalSettings.js'),
}, {
	inlineCSS: { relativeCSSInjection: true },
	minify: isProduction,
})
