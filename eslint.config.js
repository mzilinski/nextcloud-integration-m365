// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later
import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,
	{
		ignores: ['js/**', 'vendor/**', 'node_modules/**', 'docker/**'],
	},
]
