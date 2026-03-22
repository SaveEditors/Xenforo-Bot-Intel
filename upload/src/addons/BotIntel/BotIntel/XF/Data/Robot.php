<?php

namespace BotIntel\BotIntel\XF\Data;

class Robot extends XFCP_Robot
{
	public function getCoreRobotUserAgents()
	{
		return $this->sortRobotUserAgents(parent::getRobotUserAgents());
	}

	public function userAgentMatchesCoreRobot($userAgent)
	{
		$bots = $this->getCoreRobotUserAgents();

		if (preg_match(
			'#(' . implode('|', array_map('preg_quote', array_keys($bots))) . ')#i',
			strtolower($userAgent),
			$match
		))
		{
			return $bots[$match[1]];
		}

		return '';
	}

	public function getRobotUserAgents()
	{
		return $this->sortRobotUserAgents(array_merge(parent::getRobotUserAgents(), [
			'ahrefs-siteaudit' => 'ahrefs',
			'ahrefssiteaudit' => 'ahrefs',
			'applebot-extended' => 'applebot',
			'archive.org_bot' => 'archiveorg',
			'amazonbot' => 'amazon',
			'blexbot' => 'blexbot',
			'bytespider' => 'bytedance',
			'claudebot' => 'anthropic',
			'ccbot' => 'commoncrawl',
			'chatgpt-user' => 'openai',
			'dataforseobot' => 'dataforseo',
			'dotbot' => 'dotbot',
			'duckassistbot' => 'duckduckgo',
			'duckduckbot' => 'duckduckgo',
			'facebookexternalhit' => 'meta',
			'gptbot' => 'openai',
			'ia_archiver' => 'archiveorg',
			'meta-externalagent' => 'meta',
			'meta-externalfetcher' => 'meta',
			'mj12bot' => 'mj12',
			'oai-searchbot' => 'openai',
			'perplexity-user' => 'perplexity',
			'perplexitybot' => 'perplexity',
			'petalbot' => 'petalbot',
			'semrushbot' => 'semrush',
			'semrushsiteaudit' => 'semrush',
			'seznambot' => 'seznam',
		], $this->getCustomRobotUserAgents()));
	}

	public function getRobotList()
	{
		return array_merge(parent::getRobotList(), [
			'amazon' => [
				'title' => 'Amazon',
				'link' => 'https://developer.amazon.com/',
			],
			'anthropic' => [
				'title' => 'Anthropic',
				'link' => 'https://www.anthropic.com/',
			],
			'archiveorg' => [
				'title' => 'Internet Archive',
				'link' => 'https://archive.org/',
			],
			'blexbot' => [
				'title' => 'BLEXBot',
				'link' => 'https://webmeup.com/crawler.html',
			],
			'bytedance' => [
				'title' => 'ByteDance',
				'link' => 'https://www.bytedance.com/',
			],
			'commoncrawl' => [
				'title' => 'Common Crawl',
				'link' => 'https://commoncrawl.org/',
			],
			'dataforseo' => [
				'title' => 'DataForSEO',
				'link' => 'https://dataforseo.com/',
			],
			'dotbot' => [
				'title' => 'DotBot',
				'link' => 'https://moz.com/help/moz-procedures/general/what-is-dotbot',
			],
			'duckduckgo' => [
				'title' => 'DuckDuckGo',
				'link' => 'https://duckduckgo.com/duckduckbot',
			],
			'meta' => [
				'title' => 'Meta',
				'link' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/',
			],
			'mj12' => [
				'title' => 'MJ12bot',
				'link' => 'https://mj12bot.com/',
			],
			'openai' => [
				'title' => 'OpenAI',
				'link' => 'https://platform.openai.com/docs/',
			],
			'perplexity' => [
				'title' => 'Perplexity',
				'link' => 'https://www.perplexity.ai/',
			],
			'petalbot' => [
				'title' => 'PetalBot',
				'link' => 'https://aspiegel.com/petalbot',
			],
			'semrush' => [
				'title' => 'Semrush',
				'link' => 'https://www.semrush.com/bot/',
			],
			'seznam' => [
				'title' => 'SeznamBot',
				'link' => 'https://napoveda.seznam.cz/en/seznambot-crawler/',
			],
		], $this->getCustomRobotList());
	}

	protected function getCustomRobotUserAgents(): array
	{
		$bots = [];

		foreach ($this->getCustomRobotDefinitions() AS $definition)
		{
			$bots[$definition['fragment']] = $definition['family'];
		}

		return $bots;
	}

	protected function getCustomRobotList(): array
	{
		$list = [];

		foreach ($this->getCustomRobotDefinitions() AS $definition)
		{
			$list[$definition['family']] = [
				'title' => $definition['title'],
				'link' => '',
			];
		}

		return $list;
	}

	protected function getCustomRobotDefinitions(): array
	{
		$definitions = [];
		$raw = preg_split(
			'/\r?\n/',
			(string)\XF::options()->botIntelCustomRobotSignatures,
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		foreach ($raw AS $line)
		{
			$parts = array_map('trim', explode('=', $line, 3));
			$fragment = strtolower($parts[0] ?? '');
			$family = strtolower($parts[1] ?? '');
			$title = trim($parts[2] ?? '');

			$fragment = preg_replace('#\s+#', ' ', $fragment);
			$family = preg_replace('#[^a-z0-9_-]+#', '', $family);

			if ($fragment === '' || $family === '')
			{
				continue;
			}

			$definitions[$fragment] = [
				'fragment' => $fragment,
				'family' => $family,
				'title' => ($title !== '' ? $title : ucwords(str_replace(['-', '_'], ' ', $family))),
			];
		}

		return array_values($definitions);
	}

	protected function sortRobotUserAgents(array $bots): array
	{
		uksort($bots, function($a, $b)
		{
			return strlen($b) <=> strlen($a);
		});

		return $bots;
	}
}
