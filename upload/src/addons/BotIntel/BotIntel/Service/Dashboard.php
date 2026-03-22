<?php

namespace BotIntel\BotIntel\Service;

use XF\Data\Robot;
use XF\Util\Ip;

class Dashboard
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	public function __construct(\XF\App $app)
	{
		$this->app = $app;
	}

	public function getSummary(int $seconds): array
	{
		$row = $this->app->db()->fetchRow("
			SELECT
				COALESCE(SUM(request_count), 0) AS total_requests,
				COALESCE(SUM(CASE WHEN classification = 'verified_robot' THEN request_count ELSE 0 END), 0) AS verified_robot,
				COALESCE(SUM(CASE WHEN classification = 'likely_robot' THEN request_count ELSE 0 END), 0) AS likely_robot,
				COALESCE(SUM(CASE WHEN classification = 'aggressive_robot' THEN request_count ELSE 0 END), 0) AS aggressive_robot,
				COALESCE(SUM(CASE WHEN action <> 'allow' AND mode = 'enforce' THEN request_count ELSE 0 END), 0) AS enforced_interventions,
				COALESCE(SUM(CASE WHEN action <> 'allow' AND mode = 'monitor' THEN request_count ELSE 0 END), 0) AS simulated_interventions
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
		", \XF::$time - $seconds);

		return array_map('intval', $row ?: []);
	}

	public function getTopFamilies(int $seconds, int $limit = 10): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				robot_key,
				classification,
				action,
				mode,
				MAX(score) AS score,
				MAX(last_hit) AS last_hit,
				SUM(request_count) AS request_count
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
			GROUP BY robot_key, classification, action, mode
			ORDER BY request_count DESC
			LIMIT " . intval($limit) . "
		", \XF::$time - $seconds);

		foreach ($rows AS &$row)
		{
			$row['family_label'] = $this->getRobotLabel($row['robot_key']);
			$row['classification_label'] = $this->getClassificationLabel($row['classification']);
			$row['action_label'] = $this->getActionLabel($row['action'], $row['mode'] ?? 'enforce');
			$row['request_count'] = (int)$row['request_count'];
			$row['score'] = (int)$row['score'];
			$row['last_hit'] = (int)$row['last_hit'];
		}

		return $rows;
	}

	public function getTopIps(int $seconds, int $limit = 10): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				ip,
				SUBSTRING_INDEX(GROUP_CONCAT(robot_key ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS robot_key,
				SUBSTRING_INDEX(GROUP_CONCAT(classification ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS classification,
				SUBSTRING_INDEX(GROUP_CONCAT(action ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS action,
				SUBSTRING_INDEX(GROUP_CONCAT(mode ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS mode,
				MAX(last_hit) AS last_hit,
				SUM(request_count) AS request_count
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
			GROUP BY ip
			ORDER BY request_count DESC
			LIMIT " . intval($limit) . "
		", \XF::$time - $seconds);

		foreach ($rows AS &$row)
		{
			$row['ip_text'] = $this->formatIp($row['ip']);
			$row['family_label'] = $this->getRobotLabel($row['robot_key']);
			$row['classification_label'] = $this->getClassificationLabel($row['classification']);
			$row['action_label'] = $this->getActionLabel($row['action'], $row['mode'] ?? 'enforce');
			$row['request_count'] = (int)$row['request_count'];
			$row['last_hit'] = (int)$row['last_hit'];
		}

		return $rows;
	}

	public function getTopPaths(int $seconds, int $limit = 10): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				normalized_path,
				SUBSTRING_INDEX(GROUP_CONCAT(robot_key ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS robot_key,
				MAX(last_hit) AS last_hit,
				SUM(request_count) AS request_count
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
			GROUP BY normalized_path
			ORDER BY request_count DESC
			LIMIT " . intval($limit) . "
		", \XF::$time - $seconds);

		foreach ($rows AS &$row)
		{
			$row['family_label'] = $this->getRobotLabel($row['robot_key']);
			$row['normalized_path'] = $row['normalized_path'] ?: '/';
			$row['request_count'] = (int)$row['request_count'];
			$row['last_hit'] = (int)$row['last_hit'];
		}

		return $rows;
	}

	public function getTopUserAgents(int $seconds, int $limit = 12): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				user_agent,
				SUBSTRING_INDEX(GROUP_CONCAT(robot_key ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS robot_key,
				SUBSTRING_INDEX(GROUP_CONCAT(classification ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS classification,
				SUBSTRING_INDEX(GROUP_CONCAT(action ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS action,
				SUBSTRING_INDEX(GROUP_CONCAT(mode ORDER BY last_hit DESC SEPARATOR ','), ',', 1) AS mode,
				MAX(last_hit) AS last_hit,
				COUNT(DISTINCT ip) AS distinct_ips,
				SUM(request_count) AS request_count
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
			GROUP BY user_agent
			ORDER BY request_count DESC
			LIMIT " . intval($limit) . "
		", \XF::$time - $seconds);

		foreach ($rows AS &$row)
		{
			$row['user_agent'] = $row['user_agent'] ?: '(empty)';
			$row['family_label'] = $this->getRobotLabel($row['robot_key']);
			$row['classification_label'] = $this->getClassificationLabel($row['classification']);
			$row['action_label'] = $this->getActionLabel($row['action'], $row['mode'] ?? 'enforce');
			$row['request_count'] = (int)$row['request_count'];
			$row['distinct_ips'] = (int)$row['distinct_ips'];
			$row['last_hit'] = (int)$row['last_hit'];
		}

		return $rows;
	}

	public function getMinuteBursts(int $seconds, int $limit = 12): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				bucket_date,
				COUNT(DISTINCT ip) AS distinct_ips,
				COUNT(DISTINCT fingerprint) AS distinct_fingerprints,
				COUNT(DISTINCT robot_key) AS distinct_families,
				COALESCE(SUM(request_count), 0) AS total_requests,
				COALESCE(SUM(CASE WHEN classification = 'aggressive_robot' THEN request_count ELSE 0 END), 0) AS aggressive_requests,
				COALESCE(SUM(CASE WHEN action <> 'allow' AND mode = 'enforce' THEN request_count ELSE 0 END), 0) AS enforced_interventions,
				COALESCE(SUM(CASE WHEN action <> 'allow' AND mode = 'monitor' THEN request_count ELSE 0 END), 0) AS simulated_interventions
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
			GROUP BY bucket_date
			ORDER BY total_requests DESC, bucket_date DESC
			LIMIT " . intval($limit) . "
		", \XF::$time - $seconds);

		foreach ($rows AS &$row)
		{
			$row['bucket_date'] = (int)$row['bucket_date'];
			$row['distinct_ips'] = (int)$row['distinct_ips'];
			$row['distinct_fingerprints'] = (int)$row['distinct_fingerprints'];
			$row['distinct_families'] = (int)$row['distinct_families'];
			$row['total_requests'] = (int)$row['total_requests'];
			$row['aggressive_requests'] = (int)$row['aggressive_requests'];
			$row['enforced_interventions'] = (int)$row['enforced_interventions'];
			$row['simulated_interventions'] = (int)$row['simulated_interventions'];
		}

		return $rows;
	}

	public function getRecentAggressive(int $seconds, int $limit = 20): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				ip,
				robot_key,
				action,
				mode,
				score,
				last_hit,
				request_count,
				normalized_path,
				user_agent,
				reason_summary
			FROM xf_bot_intel_hit
			WHERE bucket_date >= ?
				AND classification = 'aggressive_robot'
			ORDER BY last_hit DESC
			LIMIT " . intval($limit) . "
		", \XF::$time - $seconds);

		foreach ($rows AS &$row)
		{
			$row['ip_text'] = $this->formatIp($row['ip']);
			$row['family_label'] = $this->getRobotLabel($row['robot_key']);
			$row['action_label'] = $this->getActionLabel($row['action'], $row['mode'] ?? 'enforce');
			$row['score'] = (int)$row['score'];
			$row['last_hit'] = (int)$row['last_hit'];
			$row['request_count'] = (int)$row['request_count'];
			$row['normalized_path'] = $row['normalized_path'] ?: '/';
			$row['user_agent'] = $row['user_agent'] ?: '(empty)';
		}

		return $rows;
	}

	protected function formatIp($ip): string
	{
		if (!$ip)
		{
			return '(unknown)';
		}

		$formatted = Ip::binaryToString($ip, true, false);
		return $formatted ?: '(unknown)';
	}

	protected function getRobotLabel(string $robotKey): string
	{
		if (!$robotKey || $robotKey === 'unknown')
		{
			return 'Unknown';
		}

		$info = $this->app->data(Robot::class)->getRobotInfo($robotKey);
		if ($info && !empty($info['title']))
		{
			return $info['title'];
		}

		return ucwords(str_replace(['-', '_'], ' ', $robotKey));
	}

	protected function getClassificationLabel(string $classification): string
	{
		switch ($classification)
		{
			case 'verified_robot':
				return 'Verified robot';

			case 'likely_robot':
				return 'Likely bot';

			case 'aggressive_robot':
				return 'Aggressive bot';

			default:
				return 'Human';
		}
	}

	protected function getActionLabel(string $action, string $mode = 'enforce'): string
	{
		switch ($action)
		{
			case 'deny':
				return ($mode === 'monitor' ? 'Would 403 deny' : '403 deny');

			case 'throttle':
				return ($mode === 'monitor' ? 'Would 429 throttle' : '429 throttle');

			default:
				return 'Allowed';
		}
	}
}
