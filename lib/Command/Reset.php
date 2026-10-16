<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Command;

use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Service\LifecycleService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Admin escape hatch: reset one pair, purge one user, or wipe everything before app:remove. */
class Reset extends Command {
	public function __construct(
		private CollectionMapper $collections,
		private LifecycleService $lifecycle,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('integration_m365:reset')
			->setDescription('Reset Microsoft 365 sync state (local calendars and address books are never touched)')
			->addOption('pair', 'p', InputOption::VALUE_REQUIRED, 'Forget cursors and correlations of this pair id; the next run re-pairs objects by UID')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Remove tokens, pairs and correlations of this user')
			->addOption('all', null, InputOption::VALUE_NONE, 'Remove tokens, pairs and correlations of every user (run before app:remove)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$pairId = $input->getOption('pair');
		$user = $input->getOption('user');
		$all = (bool)$input->getOption('all');
		if (($pairId !== null) + ($user !== null) + ($all ? 1 : 0) !== 1) {
			$output->writeln('<error>Give exactly one of --pair, --user, --all</error>');
			return self::INVALID;
		}
		if ($pairId !== null) {
			try {
				$pair = $this->collections->find((int)$pairId);
			} catch (DoesNotExistException) {
				$output->writeln("<error>No pair with id $pairId</error>");
				return self::FAILURE;
			}
			$this->lifecycle->resetPair($pair, $pair->getEnabled());
			$output->writeln("Pair $pairId reset; the next run lists both sides in full.");
			return self::SUCCESS;
		}
		if ($user !== null) {
			$this->lifecycle->purgeUser((string)$user);
			$output->writeln("Sync state and tokens of user $user removed.");
			return self::SUCCESS;
		}
		$users = $this->lifecycle->allUsers();
		foreach ($users as $userId) {
			$this->lifecycle->purgeUser($userId);
		}
		$output->writeln(count($users) . ' user(s) purged.');
		return self::SUCCESS;
	}
}
