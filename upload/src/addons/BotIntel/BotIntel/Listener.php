<?php

namespace BotIntel\BotIntel;

use BotIntel\BotIntel\Service\Detector;
use XF\Pub\App;

class Listener
{
	public static function appPubStartBegin(App $app): void
	{
		$detector = new Detector($app);

		if ($detector->shouldDisablePageCacheEarly())
		{
			App::$allowPageCache = false;
		}
	}
}
