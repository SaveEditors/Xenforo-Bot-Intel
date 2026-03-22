# Bot Intel

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/saveeditors)

Bot Intel is a XenForo 2.3 add-on focused on better crawler classification, cleaner online counts, request pattern analysis, and controlled crawler traffic.

## Requirements

- XenForo 2.3.x
- PHP 8.0 or newer

## Package Layout

The release ZIP follows the standard XenForo add-on structure:

- `upload/src/addons/BotIntel/BotIntel`

## Installation

1. Extract the release ZIP.
2. Upload the contents of `upload/` into your XenForo installation root.
3. In the XenForo Admin Control Panel, go to `Add-ons`.
4. Install `Bot Intel`.
5. Open `Setup -> Options -> Bot Intel` and review the default settings.

## Recommended First Run

Use these settings for initial rollout:

- `Bot Intel mode`: `Monitor only`
- `Move likely bots out of guest counts`: `Enabled`
- `Aggressive rate-limit window`: `60`
- `Default aggressive hit limit`: `20`
- `Ahrefs action`: `Throttle`
- `Ahrefs hit limit`: `8-12`

Run in monitor mode first so classifications and would-be interventions can be reviewed before enforcement is enabled.

## Admin Pages

After installation, Bot Intel adds two Admin Control Panel pages:

- `Bot Intel Overview`
- `Bot Intel Patterns`

These pages expose live XenForo vs Bot Intel detection counts, active crawler sessions, pattern filtering, user-agent analysis, burst detection, and JSON exports.

## Notes

- Bot Intel complements edge filtering and CDN controls such as Cloudflare.
- Additional verified crawler signatures can be added in `Setup -> Options -> Bot Intel`.
- If an earlier private build used a different add-on ID, uninstall that build before installing this package.

## Support

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/saveeditors)

