<?php

namespace BotIntel\BotIntel;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1()
	{
		$this->createHitTable();
		$this->createRateTable();
		$this->createLiveTable();
	}

	public function upgrade1000010Step1()
	{
		$sm = $this->schemaManager();

		if (!$sm->tableExists('xf_bot_intel_live'))
		{
			$this->createLiveTable();
		}

		$this->ensureModeColumns();
	}

	public function upgrade1001000Step1()
	{
		$this->ensureModeColumns();
	}

	public function uninstallStep1()
	{
		$sm = $this->schemaManager();
		$sm->dropTable('xf_bot_intel_hit');
		$sm->dropTable('xf_bot_intel_live');
		$sm->dropTable('xf_bot_intel_rate');
	}

	protected function createHitTable(): void
	{
		$this->schemaManager()->createTable('xf_bot_intel_hit', function(\XF\Db\Schema\Create $table)
		{
			$table->addColumn('bucket_date', 'int')->setDefault(0);
			$table->addColumn('fingerprint', 'char', 40);
			$table->addColumn('ip', 'varbinary', 16);
			$table->addColumn('robot_key', 'varchar', 25)->setDefault('');
			$table->addColumn('classification', 'varchar', 25)->setDefault('');
			$table->addColumn('action', 'varchar', 25)->setDefault('allow');
			$table->addColumn('mode', 'varchar', 25)->setDefault('enforce');
			$table->addColumn('score', 'tinyint')->setDefault(0);
			$table->addColumn('request_count', 'int')->setDefault(0);
			$table->addColumn('last_hit', 'int')->setDefault(0);
			$table->addColumn('normalized_path', 'varchar', 150)->setDefault('');
			$table->addColumn('request_method', 'varchar', 8)->setDefault('GET');
			$table->addColumn('user_agent', 'varchar', 255)->setDefault('');
			$table->addColumn('reason_summary', 'varchar', 255)->setDefault('');
			$table->addColumn('cf_bot_score', 'smallint')->nullable();

			$table->addPrimaryKey(['bucket_date', 'fingerprint']);
			$table->addKey('last_hit');
			$table->addKey(['classification', 'action'], 'classification_action');
			$table->addKey('robot_key');
			$table->addKey('ip');
		});
	}

	protected function createRateTable(): void
	{
		$this->schemaManager()->createTable('xf_bot_intel_rate', function(\XF\Db\Schema\Create $table)
		{
			$table->addColumn('bucket_key', 'char', 40);
			$table->addColumn('window_start', 'int')->setDefault(0);
			$table->addColumn('last_hit', 'int')->setDefault(0);
			$table->addColumn('expires_at', 'int')->setDefault(0);
			$table->addColumn('hit_count', 'int')->setDefault(0);
			$table->addColumn('robot_key', 'varchar', 25)->setDefault('');
			$table->addColumn('normalized_path', 'varchar', 150)->setDefault('');
			$table->addColumn('action', 'varchar', 25)->setDefault('allow');
			$table->addColumn('ip', 'varbinary', 16);

			$table->addPrimaryKey('bucket_key');
			$table->addKey('expires_at');
			$table->addKey('robot_key');
			$table->addKey('ip');
		});
	}

	protected function createLiveTable(): void
	{
		$this->schemaManager()->createTable('xf_bot_intel_live', function(\XF\Db\Schema\Create $table)
		{
			$table->addColumn('session_key', 'char', 40);
			$table->addColumn('user_id', 'int')->setDefault(0);
			$table->addColumn('ip', 'varbinary', 16);
			$table->addColumn('xf_robot_key', 'varchar', 25)->setDefault('');
			$table->addColumn('bot_robot_key', 'varchar', 25)->setDefault('');
			$table->addColumn('bot_classification', 'varchar', 25)->setDefault('human');
			$table->addColumn('bot_action', 'varchar', 25)->setDefault('allow');
			$table->addColumn('bot_mode', 'varchar', 25)->setDefault('enforce');
			$table->addColumn('bot_score', 'tinyint')->setDefault(0);
			$table->addColumn('session_hits', 'int')->setDefault(0);
			$table->addColumn('first_seen', 'int')->setDefault(0);
			$table->addColumn('last_hit', 'int')->setDefault(0);
			$table->addColumn('current_path', 'varchar', 150)->setDefault('');
			$table->addColumn('user_agent', 'varchar', 255)->setDefault('');
			$table->addColumn('reason_summary', 'varchar', 255)->setDefault('');
			$table->addColumn('cf_bot_score', 'smallint')->nullable();
			$table->addColumn('request_method', 'varchar', 8)->setDefault('GET');

			$table->addPrimaryKey('session_key');
			$table->addKey('last_hit');
			$table->addKey(['user_id', 'last_hit'], 'user_last_hit');
			$table->addKey('ip');
			$table->addKey('xf_robot_key');
			$table->addKey('bot_robot_key');
		});
	}

	protected function ensureModeColumns(): void
	{
		$sm = $this->schemaManager();

		if ($sm->tableExists('xf_bot_intel_hit') && !$sm->columnExists('xf_bot_intel_hit', 'mode'))
		{
			$sm->alterTable('xf_bot_intel_hit', function(\XF\Db\Schema\Alter $table)
			{
				$table->addColumn('mode', 'varchar', 25)->setDefault('enforce')->after('action');
			});
		}

		if ($sm->tableExists('xf_bot_intel_live') && !$sm->columnExists('xf_bot_intel_live', 'bot_mode'))
		{
			$sm->alterTable('xf_bot_intel_live', function(\XF\Db\Schema\Alter $table)
			{
				$table->addColumn('bot_mode', 'varchar', 25)->setDefault('enforce')->after('bot_action');
			});
		}
	}
}
