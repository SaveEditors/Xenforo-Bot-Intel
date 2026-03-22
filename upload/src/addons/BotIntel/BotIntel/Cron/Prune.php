<?php

namespace BotIntel\BotIntel\Cron;

class Prune
{
	public static function run(): void
	{
		$app = \XF::app();

		if (!$app->options()->botIntelEnabled)
		{
			return;
		}

		$retentionDays = max(1, (int)$app->options()->botIntelLogRetentionDays);
		$hitCutOff = \XF::$time - ($retentionDays * 86400);
		$rateCutOff = \XF::$time - 60;
		$liveCutOff = \XF::$time - max(3600, ($app->options()->onlineStatusTimeout * 60 * 6));

		$db = $app->db();
		$db->delete('xf_bot_intel_hit', 'last_hit < ?', $hitCutOff);
		$db->delete('xf_bot_intel_live', 'last_hit < ?', $liveCutOff);
		$db->delete('xf_bot_intel_rate', 'expires_at < ?', $rateCutOff);
	}
}
