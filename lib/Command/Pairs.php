<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Command;

use OCA\IntegrationM365\Db\CollectionMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Pairs extends Command {
	public function __construct(
		private CollectionMapper $collections,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_m365:pairs')
			->setDescription('List configured collection pairs')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');
		$users = $user !== null ? [(string)$user] : $this->collections->findUsersWithEnabledPairs();
		$table = new Table($output);
		$table->setHeaders(['id', 'user', 'type', 'remote', 'local uri', 'enabled', 'policy', 'last sync', 'pending', 'last error']);
		foreach ($users as $userId) {
			foreach ($this->collections->findByUser($userId) as $p) {
				$table->addRow([$p->getId(), $userId, $p->getType(), $p->getRemoteName(), $p->getLocalUri(), $p->getEnabled() ? 'yes' : 'no',
					$p->getConflictPolicy(), $p->getLastSync() ? date('Y-m-d H:i', $p->getLastSync()) : '-', $p->getPendingItems() !== null ? (string)$p->getPendingItems() : '', $p->getLastError() ?? '']);
			}
		}
		$table->render();
		return self::SUCCESS;
	}
}
