<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Command;

use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Sync\UserSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command {
	public function __construct(
		private CollectionMapper $collections,
		private UserSyncService $userSync,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_m365:sync')
			->setDescription('Synchronise Microsoft 365 collections now')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user id')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');
		$users = $user !== null ? [(string)$user] : $this->collections->findUsersWithEnabledPairs();
		foreach ($users as $userId) {
			$output->writeln("<info>$userId</info>");
			$this->userSync->syncUser($userId, (bool)$input->getOption('dry-run'));
		}
		return self::SUCCESS;
	}
}
