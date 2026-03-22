<?php

namespace BotIntel\BotIntel\Service;

use XF\Util\Ip;

class Patterns extends Dashboard
{
	public function normalizeFilters(array $input): array
	{
		$hours = (int)($input['hours'] ?? 24);
		$hours = max(1, min(168, $hours ?: 24));

		return [
			'hours' => $hours,
			'family' => $this->normalizeScalarFilter($input['family'] ?? ''),
			'classification' => $this->normalizeScalarFilter($input['classification'] ?? ''),
			'action' => $this->normalizeScalarFilter($input['action'] ?? ''),
			'mode' => $this->normalizeScalarFilter($input['mode'] ?? ''),
			'ip' => trim((string)($input['ip'] ?? '')),
			'user_agent' => trim((string)($input['user_agent'] ?? '')),
			'path' => trim((string)($input['path'] ?? '')),
		];
	}

	public function getAvailableFamilies(): array
	{
		$families = ['' => 'All families'];

		$robotList = $this->app->data(\XF\Data\Robot::class)->getRobotList();
		foreach ($robotList AS $robotKey => $info)
		{
			$families[$robotKey] = $info['title'] ?? $this->getRobotLabel($robotKey);
		}

		$dbFamilies = $this->app->db()->fetchPairs("
			SELECT robot_key, robot_key
			FROM xf_bot_intel_hit
			WHERE robot_key <> ''
			GROUP BY robot_key
			ORDER BY robot_key
		");
		foreach ($dbFamilies AS $robotKey)
		{
			if (!isset($families[$robotKey]))
			{
				$families[$robotKey] = $this->getRobotLabel($robotKey);
			}
		}

		$families['unknown'] = 'Unknown';
		asort($families, SORT_NATURAL | SORT_FLAG_CASE);
		$families = ['' => 'All families'] + array_diff_key($families, ['' => true]);

		return $families;
	}

	public function getClassificationOptions(): array
	{
		return [
			'' => 'All classifications',
			'verified_robot' => 'Verified robot',
			'likely_robot' => 'Likely bot',
			'aggressive_robot' => 'Aggressive bot',
		];
	}

	public function getActionOptions(): array
	{
		return [
			'' => 'All actions',
			'allow' => 'Allowed',
			'throttle' => '429 throttle',
			'deny' => '403 deny',
		];
	}

	public function getModeOptions(): array
	{
		return [
			'' => 'All modes',
			'enforce' => 'Enforce',
			'monitor' => 'Monitor only',
		];
	}

	public function getFilteredSummary(array $filters): array
	{
		$params = [];
		$whereSql = $this->getWhereSql($filters, $params);

		$row = $this->app->db()->fetchRow("
			SELECT
				COALESCE(SUM(request_count), 0) AS total_requests,
				COUNT(DISTINCT ip) AS distinct_ips,
				COUNT(DISTINCT user_agent) AS distinct_user_agents,
				COUNT(DISTINCT normalized_path) AS distinct_paths,
				COUNT(DISTINCT robot_key) AS distinct_families,
				COUNT(DISTINCT bucket_date) AS active_minutes,
				COALESCE(SUM(CASE WHEN classification = 'aggressive_robot' THEN request_count ELSE 0 END), 0) AS aggressive_requests,
				COALESCE(SUM(CASE WHEN action <> 'allow' AND mode = 'enforce' THEN request_count ELSE 0 END), 0) AS enforced_interventions,
				COALESCE(SUM(CASE WHEN action <> 'allow' AND mode = 'monitor' THEN request_count ELSE 0 END), 0) AS simulated_interventions,
				COALESCE(MIN(bucket_date), 0) AS first_bucket,
				COALESCE(MAX(last_hit), 0) AS last_hit
			FROM xf_bot_intel_hit
			WHERE $whereSql
		", $params);

		return array_map('intval', $row ?: []);
	}

	public function getFilteredHits(array $filters, int $limit = 200): array
	{
		$params = [];
		$whereSql = $this->getWhereSql($filters, $params);

		$rows = $this->app->db()->fetchAll("
			SELECT
				bucket_date,
				ip,
				robot_key,
				classification,
				action,
				mode,
				score,
				request_count,
				last_hit,
				normalized_path,
				request_method,
				user_agent,
				reason_summary,
				cf_bot_score
			FROM xf_bot_intel_hit
			WHERE $whereSql
			ORDER BY last_hit DESC, request_count DESC
			LIMIT " . intval($limit) . "
		", $params);

		foreach ($rows AS &$row)
		{
			$row['bucket_date'] = (int)$row['bucket_date'];
			$row['last_hit'] = (int)$row['last_hit'];
			$row['request_count'] = (int)$row['request_count'];
			$row['score'] = (int)$row['score'];
			$row['cf_bot_score'] = ($row['cf_bot_score'] === null || $row['cf_bot_score'] === '' ? null : (int)$row['cf_bot_score']);
			$row['ip_text'] = $this->formatIp($row['ip']);
			$row['family_label'] = $this->getRobotLabel($row['robot_key']);
			$row['classification_label'] = $this->getClassificationLabel($row['classification']);
			$row['action_label'] = $this->getActionLabel($row['action'], $row['mode'] ?? 'enforce');
			$row['normalized_path'] = $row['normalized_path'] ?: '/';
			$row['user_agent'] = $row['user_agent'] ?: '(empty)';
			$row['reason_summary'] = $row['reason_summary'] ?: 'None recorded';
		}

		return $rows;
	}

	public function getFilteredTopUserAgents(array $filters, int $limit = 20): array
	{
		$params = [];
		$whereSql = $this->getWhereSql($filters, $params);

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
			WHERE $whereSql
			GROUP BY user_agent
			ORDER BY request_count DESC, last_hit DESC
			LIMIT " . intval($limit) . "
		", $params);

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

	public function getFilteredMinuteBursts(array $filters, int $limit = 20): array
	{
		$params = [];
		$whereSql = $this->getWhereSql($filters, $params);

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
			WHERE $whereSql
			GROUP BY bucket_date
			ORDER BY total_requests DESC, bucket_date DESC
			LIMIT " . intval($limit) . "
		", $params);

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

	public function getExportPayload(array $filters): array
	{
		return [
			'generated_at' => \XF::$time,
			'generated_iso8601' => gmdate('c', \XF::$time),
			'filters' => $filters,
			'summary' => $this->getFilteredSummary($filters),
			'hits' => $this->getFilteredHits($filters, 500),
			'top_user_agents' => $this->getFilteredTopUserAgents($filters, 50),
			'minute_bursts' => $this->getFilteredMinuteBursts($filters, 50),
		];
	}

	protected function getWhereSql(array $filters, array &$params): string
	{
		$where = ['bucket_date >= ?'];
		$params[] = \XF::$time - (((int)$filters['hours']) * 3600);

		if ($filters['family'] !== '')
		{
			$where[] = 'robot_key = ?';
			$params[] = $filters['family'];
		}

		if ($filters['classification'] !== '')
		{
			$where[] = 'classification = ?';
			$params[] = $filters['classification'];
		}

		if ($filters['action'] !== '')
		{
			$where[] = 'action = ?';
			$params[] = $filters['action'];
		}

		if ($filters['mode'] !== '')
		{
			$where[] = 'mode = ?';
			$params[] = $filters['mode'];
		}

		if ($filters['ip'] !== '')
		{
			$ipBinary = $this->getBinaryIpOrNull($filters['ip']);
			if ($ipBinary === null)
			{
				$where[] = '1 = 0';
			}
			else
			{
				$where[] = 'ip = ?';
				$params[] = $ipBinary;
			}
		}

		if ($filters['user_agent'] !== '')
		{
			$where[] = 'user_agent LIKE ?';
			$params[] = '%' . $filters['user_agent'] . '%';
		}

		if ($filters['path'] !== '')
		{
			$where[] = 'normalized_path LIKE ?';
			$params[] = '%' . $filters['path'] . '%';
		}

		return implode(' AND ', $where);
	}

	protected function getBinaryIpOrNull(string $ip): ?string
	{
		try
		{
			return Ip::stringToBinary($ip);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	protected function normalizeScalarFilter($value): string
	{
		return strtolower(trim((string)$value));
	}
}
