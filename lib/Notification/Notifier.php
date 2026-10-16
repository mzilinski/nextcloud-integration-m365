<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Notification;

use OCA\IntegrationM365\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the "reconnect your Microsoft account" notification that OAuthService
 * raises once when Microsoft rejects the refresh token (password change, revoked
 * sessions, 90 days idle). Without it the sync would stop silently.
 */
class Notifier implements INotifier {
	public const SUBJECT_REAUTH = 'reauth_required';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Microsoft 365 integration');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== self::SUBJECT_REAUTH) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => Application::APP_ID]);
		$notification
			->setParsedSubject($l->t('Microsoft 365 synchronisation stopped: reconnect your Microsoft account'))
			->setParsedMessage($l->t('Microsoft rejected the stored login (for example after a password change). Calendars, contacts and tasks are no longer synchronised until you connect your account again in the personal settings.'))
			->setLink($settingsUrl)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'categories/integration.svg')));
		return $notification;
	}
}
