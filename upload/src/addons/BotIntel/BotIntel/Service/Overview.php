<?php

namespace BotIntel\BotIntel\Service;

use XF\Data\Robot;
use XF\Util\Ip;

class Overview
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	public function __construct(\XF\App $app)
	{
		$this->app = $app;
	}

	public function getComparison(): array
	{
		$row = $this->app->db()->fetchRow("
			SELECT
				COUNT(*) AS total,
				COALESCE(SUM(CASE WHEN user_id > 0 THEN 1 ELSE 0 END), 0) AS members,
				COALESCE(SUM(CASE WHEN user_id = 0 AND bot_robot_key = '' THEN 1 ELSE 0 END), 0) AS bot_intel_guests,
				COALESCE(SUM(CASE WHEN user_id = 0 AND bot_robot_key <> '' THEN 1 ELSE 0 END), 0) AS bot_intel_robots,
				COALESCE(SUM(CASE WHEN user_id = 0 AND xf_robot_key = '' THEN 1 ELSE 0 END), 0) AS xenforo_guests,
				COALESCE(SUM(CASE WHEN user_id = 0 AND xf_robot_key <> '' THEN 1 ELSE 0 END), 0) AS xenforo_robots
			FROM xf_bot_intel_live
			WHERE last_hit >= ?
		", $this->getOnlineCutOff());

		$row = array_map('intval', $row ?: []);
		$members = $row['members'] ?? 0;
		$botIntelGuests = $row['bot_intel_guests'] ?? 0;
		$botIntelRobots = $row['bot_intel_robots'] ?? 0;
		$xenforoGuests = $row['xenforo_guests'] ?? 0;
		$xenforoRobots = $row['xenforo_robots'] ?? 0;

		return [
			'cut_off' => $this->getOnlineCutOff(),
			'bot_intel' => [
				'guests' => $botIntelGuests,
				'members' => $members,
				'robots' => $botIntelRobots,
				'total' => $members + $botIntelGuests + $botIntelRobots,
			],
			'xenforo' => [
				'guests' => $xenforoGuests,
				'members' => $members,
				'robots' => $xenforoRobots,
				'total' => $members + $xenforoGuests + $xenforoRobots,
			],
			'delta' => [
				'guests_reclassified' => max(0, $xenforoGuests - $botIntelGuests),
				'extra_robots_found' => max(0, $botIntelRobots - $xenforoRobots),
				'robot_delta' => $botIntelRobots - $xenforoRobots,
			],
		];
	}

	public function getLiveDetections(int $limit = 100): array
	{
		$rows = $this->app->db()->fetchAll("
			SELECT
				l.*,
				u.username
			FROM xf_bot_intel_live AS l
			LEFT JOIN xf_user AS u ON (u.user_id = l.user_id)
			WHERE l.last_hit >= ?
			ORDER BY
				CASE
					WHEN l.bot_action <> 'allow' THEN 0
					WHEN l.bot_robot_key <> '' OR l.xf_robot_key <> '' THEN 1
					ELSE 2
				END,
				l.last_hit DESC
			LIMIT " . intval($limit) . "
		", $this->getOnlineCutOff());

		$ipStats = $this->getIpStats($rows);
		$retentionDays = max(1, (int)$this->app->options()->botIntelLogRetentionDays);

		foreach ($rows AS &$row)
		{
			$ipHex = strtolower(bin2hex($row['ip']));
			$stats = $ipStats[$ipHex] ?? [
				'requests_24h' => 0,
				'retained_requests' => 0,
			];

			$row['ip_text'] = $this->formatIp($row['ip']);
			$row['session_hits'] = (int)$row['session_hits'];
			$row['requests_24h'] = (int)$stats['requests_24h'];
			$row['retained_requests'] = (int)$stats['retained_requests'];
			$row['past_visits'] = $row['retained_requests'];
			$row['requests_per_day'] = round($row['retained_requests'] / $retentionDays, 2);
			$row['visits_per_day'] = $row['requests_per_day'];
			$row['first_seen'] = (int)$row['first_seen'];
			$row['last_hit'] = (int)$row['last_hit'];
			$row['bot_score'] = (int)$row['bot_score'];
			$row['bot_mode'] = $row['bot_mode'] ?: 'enforce';
			$row['bot_intel_type'] = $this->getVisitorType((int)$row['user_id'], (string)$row['bot_robot_key']);
			$row['xenforo_type'] = $this->getVisitorType((int)$row['user_id'], (string)$row['xf_robot_key']);
			$row['bot_intel_name'] = $this->getVisitorName(
				$row['bot_intel_type'],
				(string)$row['bot_robot_key'],
				(string)($row['username'] ?? '')
			);
			$row['xenforo_name'] = $this->getVisitorName(
				$row['xenforo_type'],
				(string)$row['xf_robot_key'],
				(string)($row['username'] ?? '')
			);
			$row['bot_intel_label'] = $this->getDetectionLabel($row['bot_intel_type']);
			$row['xenforo_label'] = $this->getDetectionLabel($row['xenforo_type']);
			$row['classification_label'] = $this->getClassificationLabel((string)$row['bot_classification']);
			$row['action_label'] = $this->getActionLabel((string)$row['bot_action'], (string)$row['bot_mode']);
			$row['current_path'] = $row['current_path'] ?: '/';
			$row['user_agent'] = $row['user_agent'] ?: '(empty)';
			$row['reason_summary'] = $row['reason_summary'] ?: 'None recorded';
		}

		return $rows;
	}

	public function getExportPayload(Dashboard $dashboard, int $liveLimit = 250): array
	{
		$comparison = $this->getComparison();

		return [
			'generated_at' => \XF::$time,
			'generated_iso8601' => gmdate('c', \XF::$time),
			'online_cut_off' => $this->getOnlineCutOff(),
			'comparison' => $comparison,
			'bot_intel_detections' => $comparison['bot_intel'],
			'xenforo_detections' => $comparison['xenforo'],
			'live_detections' => $this->getLiveDetections($liveLimit),
			'tracked_last_hour' => $dashboard->getSummary(3600),
			'tracked_last_day' => $dashboard->getSummary(86400),
			'top_families' => $dashboard->getTopFamilies(86400, 15),
			'top_ips' => $dashboard->getTopIps(86400, 15),
			'top_paths' => $dashboard->getTopPaths(86400, 15),
			'top_user_agents' => $dashboard->getTopUserAgents(86400, 15),
			'minute_bursts' => $dashboard->getMinuteBursts(86400, 15),
			'recent_aggressive' => $dashboard->getRecentAggressive(86400, 25),
		];
	}

	protected function getIpStats(array $rows): array
	{
		$ips = [];
		foreach ($rows AS $row)
		{
			if (!empty($row['ip']))
			{
				$ips[strtolower(bin2hex($row['ip']))] = $row['ip'];
			}
		}

		if (!$ips)
		{
			return [];
		}

		$params = [\XF::$time - 86400];
		foreach ($ips AS $ip)
		{
			$params[] = $ip;
		}

		$placeholders = implode(', ', array_fill(0, count($ips), '?'));
		$stats = $this->app->db()->fetchAll("
			SELECT
				LOWER(HEX(ip)) AS ip_hex,
				COALESCE(SUM(request_count), 0) AS retained_requests,
				COALESCE(SUM(CASE WHEN last_hit >= ? THEN request_count ELSE 0 END), 0) AS requests_24h
			FROM xf_bot_intel_hit
			WHERE ip IN ($placeholders)
			GROUP BY ip
		", $params);

		$output = [];
		foreach ($stats AS $stat)
		{
			$output[$stat['ip_hex']] = [
				'retained_requests' => (int)$stat['retained_requests'],
				'requests_24h' => (int)$stat['requests_24h'],
			];
		}

		return $output;
	}

	protected function getVisitorType(int $userId, string $robotKey): string
	{
		if ($userId > 0)
		{
			return 'member';
		}

		return ($robotKey !== '' ? 'robot' : 'guest');
	}

	protected function getVisitorName(string $type, string $robotKey, string $username): string
	{
		switch ($type)
		{
			case 'member':
				return ($username !== '' ? $username : 'Member');

			case 'robot':
				return $this->getRobotLabel($robotKey);

			default:
				return 'Guest';
		}
	}

	protected function getDetectionLabel(string $type): string
	{
		switch ($type)
		{
			case 'member':
				return 'Member';

			case 'robot':
				return 'Robot';

			default:
				return 'Guest';
		}
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

	protected function formatIp($ip): string
	{
		if (!$ip)
		{
			return '(unknown)';
		}

		$formatted = Ip::binaryToString($ip, true, false);
		return ($formatted ?: '(unknown)');
	}

	protected function getOnlineCutOff(): int
	{
		return \XF::$time - ($this->app->options()->onlineStatusTimeout * 60);
	}
}
