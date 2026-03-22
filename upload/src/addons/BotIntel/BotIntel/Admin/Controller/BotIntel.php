<?php

namespace BotIntel\BotIntel\Admin\Controller;

use BotIntel\BotIntel\Service\Dashboard;
use BotIntel\BotIntel\Service\Overview;
use BotIntel\BotIntel\Service\Patterns;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\Reply\AbstractReply;

class BotIntel extends AbstractController
{
	protected function preDispatchController($action, \XF\Mvc\ParameterBag $params)
	{
		$this->assertAdminPermission('viewLogs');
	}

	public function actionIndex(): AbstractReply
	{
		$dashboard = new Dashboard($this->app());
		$overview = new Overview($this->app());

		$viewParams = [
			'botIntelMode' => (string)$this->app()->options()->botIntelMode,
			'comparison' => $overview->getComparison(),
			'liveDetections' => $overview->getLiveDetections(150),
			'lastHourSummary' => $dashboard->getSummary(3600),
			'lastDaySummary' => $dashboard->getSummary(86400),
			'topFamilies' => $dashboard->getTopFamilies(86400, 12),
			'topIps' => $dashboard->getTopIps(86400, 12),
			'topPaths' => $dashboard->getTopPaths(86400, 12),
			'topUserAgents' => $dashboard->getTopUserAgents(86400, 12),
			'minuteBursts' => $dashboard->getMinuteBursts(86400, 12),
			'recentAggressive' => $dashboard->getRecentAggressive(86400, 20),
		];

		return $this->view('', 'bot_intel_overview', $viewParams);
	}

	public function actionPatterns(): AbstractReply
	{
		$patterns = new Patterns($this->app());
		$filters = $patterns->normalizeFilters($this->getPatternFilterInput());

		$viewParams = [
			'filters' => $filters,
			'availableFamilies' => $patterns->getAvailableFamilies(),
			'classificationOptions' => $patterns->getClassificationOptions(),
			'actionOptions' => $patterns->getActionOptions(),
			'modeOptions' => $patterns->getModeOptions(),
			'summary' => $patterns->getFilteredSummary($filters),
			'patternHits' => $patterns->getFilteredHits($filters, 200),
			'patternTopUserAgents' => $patterns->getFilteredTopUserAgents($filters, 20),
			'patternMinuteBursts' => $patterns->getFilteredMinuteBursts($filters, 20),
		];

		return $this->view('', 'bot_intel_patterns', $viewParams);
	}

	public function actionExport(): AbstractReply
	{
		$dashboard = new Dashboard($this->app());
		$overview = new Overview($this->app());

		return $this->jsonDownloadReply(
			$overview->getExportPayload($dashboard, 500),
			'bot-intel-overview'
		);
	}

	public function actionPatternsExport(): AbstractReply
	{
		$patterns = new Patterns($this->app());
		$filters = $patterns->normalizeFilters($this->getPatternFilterInput());

		return $this->jsonDownloadReply(
			$patterns->getExportPayload($filters),
			'bot-intel-patterns'
		);
	}

	protected function getPatternFilterInput(): array
	{
		return [
			'hours' => $this->filter('hours', 'uint'),
			'family' => $this->filter('family', 'str'),
			'classification' => $this->filter('classification', 'str'),
			'action' => $this->filter('action', 'str'),
			'mode' => $this->filter('mode', 'str'),
			'ip' => $this->filter('ip', 'str'),
			'user_agent' => $this->filter('user_agent', 'str'),
			'path' => $this->filter('path', 'str'),
		];
	}

	protected function jsonDownloadReply(array $payload, string $filePrefix): AbstractReply
	{
		$this->setResponseType('raw');

		$json = json_encode(
			$payload,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);

		$view = $this->view('', '', [
			'innerContent' => ($json !== false ? $json : '{}')
		]);
		$view->setResponseHeader('Content-Type', 'application/json; charset=utf-8');
		$view->setResponseHeader(
			'Content-Disposition',
			'attachment; filename="' . $filePrefix . '-' . gmdate('Ymd-His', \XF::$time) . '.json"'
		);

		return $view;
	}
}
