[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/saveeditors)

# Bot Intel

Bot Intel is a XenForo 2.3 add-on for forum operators who need better visibility into crawler traffic, more accurate guest counts, and practical controls for high-volume bot activity.

It extends XenForo's stock robot handling with layered bot classification, live comparison against core detection, request pattern analysis, JSON exports, and configurable crawler throttling for aggressive families such as Ahrefs, Semrush, MJ12, DotBot, BLEXBot, and Common Crawl.

## Status

`v1.0b` is the first public beta release. It is intended for production-capable testing on XenForo 2.3 forums and should be rolled out in `Monitor only` mode first.

## Features

- Expanded verified crawler signatures beyond XenForo's stock robot list
- Heuristic classification for suspicious guests that would otherwise inflate online guest counts
- Live Bot Intel vs XenForo comparison inside the Admin Control Panel
- Active session visibility with IP, robot family, user agent, path, and request history
- Pattern analysis for burst traffic, repeated user agents, repeated paths, and per-IP activity
- Pretty-printed JSON exports for overview and pattern datasets
- Configurable per-family throttling with monitor and enforce modes
- Ahrefs-specific handling so SEO crawlers can be observed and rate-limited instead of blindly blocked

## Requirements

- XenForo 2.3.0 or newer
- PHP 8.0 or newer

## Installation

1. Download the latest release ZIP from the `releases/` directory or GitHub Releases.
2. Extract the archive.
3. Upload the contents of `upload/` to the root of your XenForo installation.
4. In the XenForo Admin Control Panel, open `Add-ons`.
5. Install `Bot Intel`.
6. Open `Setup -> Options -> Bot Intel` and confirm the initial settings.

## Recommended Initial Configuration

Use these values for first deployment:

- `Bot Intel mode`: `Monitor only`
- `Move likely bots out of guest counts`: `Enabled`
- `Tracked hit retention`: `14` days
- `Aggressive rate-limit window`: `60` seconds
- `Default aggressive hit limit`: `20`
- `Ahrefs action`: `Throttle`
- `Ahrefs hit limit`: `8-12`

Monitor traffic for a few days before switching to `Enforce`.

## Admin Control Panel

Bot Intel adds two ACP pages:

- `Bot Intel Overview`
- `Bot Intel Patterns`

`Overview` is intended for live traffic review. It compares Bot Intel detections against XenForo detections, highlights extra robots found, shows active sessions, and surfaces the busiest paths, IPs, and robot families.

`Patterns` is intended for investigation and tuning. It supports filtering by time window, family, classification, action, mode, IP, path fragment, and user-agent fragment so crawl waves and suspicious guest traffic can be isolated quickly.

## Release Layout

The repository ships the add-on in the standard XenForo structure:

- `upload/src/addons/BotIntel/BotIntel`

Release archives are stored in:

- `releases/`

## Operational Notes

- Start in monitor mode and review `Would 429 throttle` and `Would 403 deny` events before enabling enforcement.
- Use `Custom verified robot signatures` for site-specific crawler fingerprints that should be treated as known robots.
- Pair Bot Intel with edge-layer controls such as Cloudflare rate limiting and targeted `robots.txt` rules for low-value crawl surfaces.
- If you previously installed an older private preview under a different add-on ID, uninstall that preview before installing this public package.


