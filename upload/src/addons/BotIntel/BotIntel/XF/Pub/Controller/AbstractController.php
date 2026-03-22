<?php

namespace BotIntel\BotIntel\XF\Pub\Controller;

use BotIntel\BotIntel\Service\Detector;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Repository\SessionActivityRepository;

class AbstractController extends XFCP_AbstractController
{
	protected function preDispatchType($action, ParameterBag $params)
	{
		$detector = new Detector($this->app());
		$analysis = $detector->analyze();

		if ($analysis['classification'] === 'aggressive_robot' && $analysis['response_action'] !== 'allow')
		{
			$detector->logCurrentDetection($analysis['action']);

			$reply = $this->view('', 'bot_intel_rate_limited', [
				'detection' => $analysis,
			]);
			$reply->setResponseCode($analysis['response_action'] === 'deny' ? 403 : 429);

			if ($analysis['response_action'] === 'throttle' && $analysis['window_seconds'])
			{
				$reply->setResponseHeader('Retry-After', (string)$analysis['window_seconds']);
			}

			throw $this->exception($reply);
		}

		parent::preDispatchType($action, $params);
	}

	protected function updateSessionActivity($action, ParameterBag $params, AbstractReply &$reply)
	{
		if ($this->canUpdateSessionActivity($action, $params, $reply, $viewState))
		{
			$controller = $this->app->extension()->resolveExtendedClassToRoot($this);

			$reply->setViewOption('sessionActivity', [
				'controller' => $controller,
				'action' => $action,
				'params' => $params->params(),
				'viewState' => $viewState,
			]);

			if ($this->request->isPrefetch())
			{
				return;
			}

			$detector = new Detector($this->app());
			$analysis = $detector->analyze();

			$activityRepo = $this->repository(SessionActivityRepository::class);
			$activityRepo->updateSessionActivity(
				\XF::visitor()->user_id,
				$this->request->getIp(),
				$controller,
				$action,
				$params->params(),
				$viewState,
				$analysis['session_robot_key']
			);

			$detector->updateLiveDetection();
			$detector->logCurrentDetection($analysis['action']);
		}
	}
}
