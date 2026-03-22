<?php

namespace BotIntel\BotIntel\Service;

use XF\Data\Robot;
use XF\Util\Ip;

class Detector
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	/**
	 * @var array<int, array>
	 */
	protected static $analysisCache = [];

	/**
	 * @var array<int, bool>
	 */
	protected static $logged = [];

	public function __construct(\XF\App $app)
	{
		$this->app = $app;
	}

	public function shouldDisablePageCacheEarly(): bool
	{
		if (!$this->isEnabled())
		{
			return false;
		}

		$request = $this->app->request();
		$requestMethod = strtoupper($request->getRequestMethod());
		$userAgent = trim((string)$request->getUserAgent());
		$hasSessionHint = (bool)$request->getCookie('session') || (bool)$request->getCookie('user');
		$accept = strtolower(trim((string)$request->getServer('HTTP_ACCEPT', '')));
		$cfBotScore = $this->getCloudflareBotScore();

		if (in_array($requestMethod, ['HEAD', 'OPTIONS'], true))
		{
			return true;
		}

		if ($userAgent === '')
		{
			return true;
		}

		if ($this->app->data(Robot::class)->userAgentMatchesRobot($userAgent))
		{
			return true;
		}

		if ($this->matchesAutomationUserAgent($userAgent))
		{
			return true;
		}

		if ($cfBotScore !== null && $cfBotScore < 60)
		{
			return true;
		}

		if (
			!$hasSessionHint
			&& $this->isBrowserLikeUserAgent($userAgent)
			&& !$request->getServer('HTTP_SEC_CH_UA')
		)
		{
			return true;
		}

		if (!$hasSessionHint && ($accept === '' || $accept === '*/*'))
		{
			return true;
		}

		return (
			!$hasSessionHint
			&& !$request->getServer('HTTP_ACCEPT_LANGUAGE')
		);
	}

	public function analyze(): array
	{
		$request = $this->app->request();
		$cacheKey = spl_object_id($request);

		if (isset(self::$analysisCache[$cacheKey]))
		{
			return self::$analysisCache[$cacheKey];
		}

		$result = [
			'ip' => (string)$request->getIp(),
			'user_agent' => $this->trimToLength(trim((string)$request->getUserAgent()), 255),
			'request_method' => strtoupper($request->getRequestMethod()),
			'normalized_path' => $this->normalizeRoutePath($request->getRoutePath()),
			'xf_robot_key' => '',
			'xf_family_label' => 'Unknown',
			'family' => '',
			'family_label' => 'Unknown',
			'session_robot_key' => '',
			'classification' => 'human',
			'action' => 'allow',
			'response_action' => 'allow',
			'mode' => $this->getMode(),
			'score' => 0,
			'reasons' => [],
			'window_count' => 0,
			'window_limit' => 0,
			'window_seconds' => max(15, (int)$this->app->options()->botIntelAggressiveWindow),
			'cf_bot_score' => $this->getCloudflareBotScore(),
			'should_track' => false,
			'disable_page_cache' => false,
		];

		if (!$this->isEnabled())
		{
			self::$analysisCache[$cacheKey] = $result;
			return $result;
		}

		$visitor = \XF::visitor();
		$visitorId = $visitor ? (int)$visitor->user_id : 0;
		$hasSessionHint = (bool)$request->getCookie('session') || (bool)$request->getCookie('user');
		$userAgent = $result['user_agent'];
		$robotData = $this->app->data(Robot::class);
		$coreRobotKey = $userAgent !== '' && method_exists($robotData, 'userAgentMatchesCoreRobot')
			? $robotData->userAgentMatchesCoreRobot($userAgent)
			: '';
		$knownRobotKey = $userAgent !== ''
			? $robotData->userAgentMatchesRobot($userAgent)
			: '';

		$result['xf_robot_key'] = $coreRobotKey;
		$result['xf_family_label'] = $this->getRobotLabel($coreRobotKey);
		$result['family'] = $knownRobotKey;
		$result['family_label'] = $this->getRobotLabel($knownRobotKey);

		if (!$visitorId)
		{
			if ($knownRobotKey)
			{
				$result['session_robot_key'] = $knownRobotKey;
				$result['classification'] = 'verified_robot';
				$result['score'] = 70;
				$result['reasons'][] = 'matched_known_robot_user_agent';
			}
			else
			{
				$this->applyHeuristics($result, $hasSessionHint);
			}

			$rateLimitConfig = $this->getRateLimitConfig(
				$knownRobotKey ?: $result['session_robot_key'],
				$result['score'],
				$result['request_method']
			);

			if ($rateLimitConfig)
			{
				$result['window_limit'] = $rateLimitConfig['limit'];
				$result['window_count'] = $this->incrementRateCounter(
					$result['ip'],
					$knownRobotKey ?: ($result['session_robot_key'] ?: 'unknown'),
					$result['normalized_path'],
					$rateLimitConfig['action']
				);

				if ($rateLimitConfig['action'] !== 'allow' && $result['window_count'] > $result['window_limit'])
				{
					$result['action'] = $rateLimitConfig['action'];
					$result['classification'] = 'aggressive_robot';
					$result['score'] = max($result['score'], 85);
					$result['session_robot_key'] = $result['session_robot_key'] ?: ($knownRobotKey ?: 'unknown');
					$result['family'] = $result['session_robot_key'];
					$result['family_label'] = $this->getRobotLabel($result['family']);
					$result['reasons'][] = 'rate_limit_exceeded';
				}
			}

			$result['should_track'] = (
				(bool)$knownRobotKey
				|| $result['classification'] !== 'human'
			);
			$result['disable_page_cache'] = (
				(bool)$knownRobotKey
				|| $result['classification'] !== 'human'
				|| $this->matchesAutomationUserAgent($userAgent)
			);
		}

		if (!$result['family'] && $result['session_robot_key'])
		{
			$result['family'] = $result['session_robot_key'];
			$result['family_label'] = $this->getRobotLabel($result['family']);
		}

		$result['response_action'] = $result['action'];
		if ($result['mode'] === 'monitor' && $result['action'] !== 'allow')
		{
			$result['response_action'] = 'allow';
			$result['reasons'][] = 'monitor_mode_no_enforcement';
		}

		self::$analysisCache[$cacheKey] = $result;

		return $result;
	}

	public function logCurrentDetection(?string $action = null): void
	{
		$request = $this->app->request();
		$cacheKey = spl_object_id($request);

		if (isset(self::$logged[$cacheKey]))
		{
			return;
		}

		$analysis = $this->analyze();
		if (!$analysis['should_track'])
		{
			return;
		}

		$action = $action ?: $analysis['action'];
		$ipBinary = $this->getBinaryIp($analysis['ip']);
		$fingerprint = sha1(
			$analysis['ip']
			. '|'
			. ($analysis['session_robot_key'] ?: 'unknown')
			. '|'
			. $analysis['classification']
			. '|'
			. $analysis['normalized_path']
			. '|'
			. substr($analysis['user_agent'], 0, 120)
		);
		$bucketDate = (int)(floor(\XF::$time / 60) * 60);
		$reasonSummary = $this->trimToLength(
			implode('; ', array_slice($analysis['reasons'], 0, 4)),
			255
		);

		$this->app->db()->query("
			INSERT INTO xf_bot_intel_hit (
				bucket_date,
				fingerprint,
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
			) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE
				request_count = request_count + 1,
				last_hit = VALUES(last_hit),
				action = VALUES(action),
				mode = VALUES(mode),
				score = GREATEST(score, VALUES(score)),
				reason_summary = VALUES(reason_summary),
				cf_bot_score = VALUES(cf_bot_score)
		", [
			$bucketDate,
			$fingerprint,
			$ipBinary,
			$this->trimToLength($analysis['session_robot_key'], 25),
			$this->trimToLength($analysis['classification'], 25),
			$this->trimToLength($action, 25),
			$this->trimToLength($analysis['mode'], 25),
			(int)$analysis['score'],
			\XF::$time,
			$this->trimToLength($analysis['normalized_path'], 150),
			$this->trimToLength($analysis['request_method'], 8),
			$this->trimToLength($analysis['user_agent'], 255),
			$reasonSummary,
			$analysis['cf_bot_score'],
		]);

		self::$logged[$cacheKey] = true;
	}

	public function updateLiveDetection(): void
	{
		$analysis = $this->analyze();
		$visitor = \XF::visitor();
		$userId = $visitor ? (int)$visitor->user_id : 0;
		$ipBinary = $this->getBinaryIp($analysis['ip']);
		$sessionKey = sha1(
			($userId ? 'u:' . $userId : 'g:' . bin2hex($ipBinary))
		);
		$reasonSummary = $this->trimToLength(
			implode('; ', array_slice($analysis['reasons'], 0, 4)),
			255
		);

		$this->app->db()->query("
			INSERT INTO xf_bot_intel_live (
				session_key,
				user_id,
				ip,
				xf_robot_key,
				bot_robot_key,
				bot_classification,
				bot_action,
				bot_mode,
				bot_score,
				session_hits,
				first_seen,
				last_hit,
				current_path,
				user_agent,
				reason_summary,
				cf_bot_score,
				request_method
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE
				user_id = VALUES(user_id),
				ip = VALUES(ip),
				xf_robot_key = VALUES(xf_robot_key),
				bot_robot_key = VALUES(bot_robot_key),
				bot_classification = VALUES(bot_classification),
				bot_action = VALUES(bot_action),
				bot_mode = VALUES(bot_mode),
				bot_score = VALUES(bot_score),
				session_hits = session_hits + 1,
				last_hit = VALUES(last_hit),
				current_path = VALUES(current_path),
				user_agent = VALUES(user_agent),
				reason_summary = VALUES(reason_summary),
				cf_bot_score = VALUES(cf_bot_score),
				request_method = VALUES(request_method)
		", [
			$sessionKey,
			$userId,
			$ipBinary,
			$this->trimToLength($analysis['xf_robot_key'], 25),
			$this->trimToLength($analysis['session_robot_key'], 25),
			$this->trimToLength($analysis['classification'], 25),
			$this->trimToLength($analysis['action'], 25),
			$this->trimToLength($analysis['mode'], 25),
			(int)$analysis['score'],
			\XF::$time,
			\XF::$time,
			$this->trimToLength($analysis['normalized_path'], 150),
			$this->trimToLength($analysis['user_agent'], 255),
			$reasonSummary,
			$analysis['cf_bot_score'],
			$this->trimToLength($analysis['request_method'], 8),
		]);
	}

	protected function applyHeuristics(array &$result, bool $hasSessionHint): void
	{
		$request = $this->app->request();
		$userAgent = $result['user_agent'];
		$accept = strtolower(trim((string)$request->getServer('HTTP_ACCEPT', '')));

		if ($userAgent === '')
		{
			$result['score'] += 50;
			$result['reasons'][] = 'missing_user_agent';
		}

		if ($this->matchesAutomationUserAgent($userAgent))
		{
			$result['score'] += 65;
			$result['reasons'][] = 'automation_user_agent';
		}

		if ($result['request_method'] === 'HEAD' || $result['request_method'] === 'OPTIONS')
		{
			$result['score'] += 15;
			$result['reasons'][] = 'non_browser_request_method';
		}

		if ($accept === '')
		{
			$result['score'] += 15;
			$result['reasons'][] = 'missing_accept_header';
		}
		else if ($accept === '*/*')
		{
			$result['score'] += 10;
			$result['reasons'][] = 'generic_accept_header';
		}

		if (!$request->getServer('HTTP_ACCEPT_LANGUAGE'))
		{
			$result['score'] += 8;
			$result['reasons'][] = 'missing_accept_language';
		}

		if (!$request->getServer('HTTP_SEC_CH_UA') && $this->isBrowserLikeUserAgent($userAgent))
		{
			$result['score'] += 12;
			$result['reasons'][] = 'browser_claim_without_client_hints';
		}

		if (!$hasSessionHint)
		{
			$result['score'] += 5;
			$result['reasons'][] = 'no_session_cookie';
		}

		if ($result['cf_bot_score'] !== null)
		{
			if ($result['cf_bot_score'] < 30)
			{
				$result['score'] += 35;
				$result['reasons'][] = 'low_cloudflare_bot_score';
			}
			else if ($result['cf_bot_score'] < 60)
			{
				$result['score'] += 15;
				$result['reasons'][] = 'middling_cloudflare_bot_score';
			}
		}

		if (
			$hasSessionHint
			&& $this->isBrowserLikeUserAgent($userAgent)
			&& !$this->matchesAutomationUserAgent($userAgent)
		)
		{
			$result['score'] = max(0, $result['score'] - 15);
		}

		if (
			$result['score'] >= 45
			&& $this->app->options()->botIntelTrackLikelyBotsAsRobots
		)
		{
			$result['session_robot_key'] = 'unknown';
			$result['classification'] = 'likely_robot';
			$result['family_label'] = 'Unknown';
		}
	}

	protected function getRateLimitConfig(string $family, int $score, string $requestMethod): ?array
	{
		if (!in_array($requestMethod, ['GET', 'HEAD'], true))
		{
			return null;
		}

		$family = strtolower(trim($family));
		if ($family === 'unknown')
		{
			$family = '';
		}

		if ($family === 'ahrefs')
		{
			return [
				'action' => (string)$this->app->options()->botIntelAhrefsAction,
				'limit' => max(1, (int)$this->app->options()->botIntelAhrefsHits),
			];
		}

		if ($family && in_array($family, $this->getRateLimitedFamilies(), true))
		{
			return [
				'action' => 'throttle',
				'limit' => max(5, (int)$this->app->options()->botIntelAggressiveHits),
			];
		}

		if (!$family && $score >= 65)
		{
			return [
				'action' => 'throttle',
				'limit' => max(5, (int)$this->app->options()->botIntelAggressiveHits),
			];
		}

		return null;
	}

	protected function incrementRateCounter(string $ip, string $family, string $normalizedPath, string $action): int
	{
		$windowSeconds = max(15, (int)$this->app->options()->botIntelAggressiveWindow);
		$bucketKey = sha1($ip . '|' . $family);
		$windowStart = (int)(floor(\XF::$time / $windowSeconds) * $windowSeconds);
		$expiresAt = $windowStart + ($windowSeconds * 2);

		$this->app->db()->query("
			INSERT INTO xf_bot_intel_rate (
				bucket_key,
				window_start,
				last_hit,
				expires_at,
				hit_count,
				robot_key,
				normalized_path,
				action,
				ip
			) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE
				hit_count = hit_count + 1,
				last_hit = VALUES(last_hit),
				expires_at = VALUES(expires_at),
				normalized_path = VALUES(normalized_path),
				action = VALUES(action)
		", [
			$bucketKey,
			$windowStart,
			\XF::$time,
			$expiresAt,
			$this->trimToLength($family, 25),
			$this->trimToLength($normalizedPath, 150),
			$this->trimToLength($action, 25),
			$this->getBinaryIp($ip),
		]);

		return (int)$this->app->db()->fetchOne("
			SELECT hit_count
			FROM xf_bot_intel_rate
			WHERE bucket_key = ?
		", $bucketKey);
	}

	protected function matchesAutomationUserAgent(string $userAgent): bool
	{
		if ($userAgent === '')
		{
			return false;
		}

		return (bool)preg_match(
			'#(bot|crawler|spider|scraper|headless|curl|wget|python-requests|aiohttp|scrapy|go-http-client|okhttp|axios|node-fetch|guzzlehttp|libwww-perl|java/|httpclient|feedfetcher)#i',
			$userAgent
		);
	}

	protected function isBrowserLikeUserAgent(string $userAgent): bool
	{
		return (bool)preg_match(
			'#(mozilla/5\.0|chrome/|firefox/|safari/|edg/|opr/)#i',
			$userAgent
		);
	}

	protected function normalizeRoutePath(string $routePath): string
	{
		$routePath = strtolower(trim($routePath, '/'));
		if ($routePath === '')
		{
			return '/';
		}

		$routePath = preg_replace('#page-\d+#', 'page-*', $routePath);
		$routePath = preg_replace('#/[0-9]+(?=/|$)#', '/{id}', $routePath);
		$routePath = preg_replace('#\b[0-9]{4,}\b#', '{id}', $routePath);
		$routePath = preg_replace('#/[a-f0-9]{8,}(?=/|$)#', '/{hash}', $routePath);

		return '/' . $this->trimToLength($routePath, 149);
	}

	protected function getCloudflareBotScore(): ?int
	{
		$request = $this->app->request();

		foreach (['HTTP_CF_BOT_SCORE', 'HTTP_CF_BOT_MANAGEMENT_SCORE'] AS $header)
		{
			$value = $request->getServer($header);
			if ($value !== false && $value !== null && $value !== '')
			{
				return max(0, min(100, (int)$value));
			}
		}

		return null;
	}

	protected function getRateLimitedFamilies(): array
	{
		$raw = preg_split(
			'/\r?\n/',
			(string)$this->app->options()->botIntelRateLimitedFamilies,
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		$families = [];
		foreach ($raw AS $family)
		{
			$family = strtolower(trim($family));
			if ($family !== '')
			{
				$families[$family] = $family;
			}
		}

		return array_values($families);
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

	protected function getBinaryIp(string $ip): string
	{
		try
		{
			return Ip::stringToBinary($ip);
		}
		catch (\Throwable $e)
		{
			return (string)@inet_pton('0.0.0.0');
		}
	}

	protected function trimToLength(string $value, int $length): string
	{
		if (strlen($value) <= $length)
		{
			return $value;
		}

		return substr($value, 0, $length);
	}

	protected function isEnabled(): bool
	{
		return (bool)$this->app->options()->botIntelEnabled;
	}

	protected function getMode(): string
	{
		$mode = (string)$this->app->options()->botIntelMode;
		return ($mode === 'monitor' ? 'monitor' : 'enforce');
	}
}
