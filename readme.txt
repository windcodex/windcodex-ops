=== WindCodex Ops – Safe AI Actions ===
Contributors: windcodex
Tags: claude, chatgpt, mcp server, ai agent, automation
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.1.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT, and MCP AI platforms to WordPress with safe, pre-approved content, media, SEO, and site health tools. No code execution.

== Description ==

**WindCodex Ops** connects Claude, ChatGPT, and other MCP-compatible AI platforms to your WordPress site through a fixed, tested list of actions – not open-ended code execution. If an action isn't on the list, the AI simply cannot do it, full stop.

This plugin covers everyday content management, entirely free: posts, pages, media, SEO, site structure, and site health.

= Why Site Owners Choose WindCodex Ops =

* **No code execution, ever** – the AI can only call the fixed list of tools this plugin ships with. There is no PHP execution, no arbitrary SQL, no shell access anywhere in the codebase.
* **Safe on live, customer-facing sites** – every tool is tested and scoped to a specific job (update a meta tag, insert a block, resize an image) rather than a general-purpose capability.
* **Undo built in** – write actions that support it keep an undo window (72 hours by default, extendable to 96 or 168 hours in Settings), so a mistaken edit is a rollback, not an incident. Undo covers posts, pages, categories, and tags. Undo history and the visible activity feed are independent settings, so you can hide the feed without losing the undo safety net.
* **Works with any MCP-compatible AI platform** – Claude, ChatGPT, and any other client that speaks the Model Context Protocol connects the same way, over standard OAuth.
* **You control who can connect** – only Administrators can connect an AI app by default, and each connection can only do what its approving user could do in the dashboard.
* **No license key, no paywall** – every tool group in this plugin is free and on by default.

= Key Features =

100+ tools across 5 groups, entirely free, no license key required, every group on by default:

* **Content management** – posts, pages, categories, tags, revisions, and Gutenberg content blocks (read, insert, remove, and update one in place by position).
* **Media and assets** – uploads, alt text, compression, format conversion, and usage lookup so the AI can tell you where an image is used before touching it.
* **SEO and discoverability** – meta tags, focus keyword, canonical URL, Open Graph, and readability tools that auto-detect Yoast SEO, Rank Math, All in One SEO, and SEOPress, so the same tools work correctly no matter which one is active.
* **Site structure** – menus, navigation blocks, classic widgets, redirects, sitemap status, and read-only permalink structure lookup.
* **Site health and diagnostics** – status checks, error logs, cron health, database/disk usage, and orphaned-data cleanup.

A flat 120 requests/minute rate limit applies. The undo window (72 hours by default, selectable 72, 96, or 168) applies to every write tool that supports undo: posts, pages, categories, and tags in this plugin.

= Use Cases =

* Let an AI assistant draft, edit, and publish blog posts and pages without giving it FTP or database access.
* Keep SEO metadata (titles, meta descriptions, Open Graph tags) consistent across every post, regardless of which SEO plugin the site runs.
* Batch-update image alt text and compress media for accessibility and page speed, from a chat interface.
* Clean up broken permalinks, stale redirects, and orphaned post meta without opening the database.
* Monitor site health – error logs, cron status, disk usage – and get a plain-language summary instead of digging through wp-admin screens.
* Insert or update a specific Gutenberg block on a page programmatically, without touching the rest of the content.

= For Store Owners =

The content, media, SEO, and structure tools below work on any post type, including WooCommerce products, so an AI assistant can help with all of this out of the box:

* Rewrite and standardize product page titles and meta descriptions ahead of a sale or new collection launch, without opening each product one by one.
* Compress and add missing alt text across a product image gallery in one pass – smaller images load faster on mobile, and alt text is what image search actually indexes.
* Set up a 301 redirect the moment a product is discontinued or its URL changes, so existing links and search rankings don't turn into 404s.
* Find every page and post referencing a specific image before swapping it out for a new banner or seasonal promo graphic.
* Add a limited-time menu item (e.g. a Black Friday or holiday collection link) to site navigation and remove it again afterward, without a developer touching the theme.
* Check sitemap status and crawl-facing site health before a big traffic push, so search engines can actually find the pages being promoted.

= Risk Levels =

Every tool group is tagged low or medium risk, shown as a badge in **Settings > WindCodex Ops > Tools** – this is informational, not a gate. Every group is on by default; the risk label helps a site owner decide which groups to turn *off* for their particular site.

= How It Works =

1. Install and activate the plugin.
2. Go to **Settings > WindCodex Ops** and review which tool groups are enabled.
3. Copy the Connector URL from the Connection tab and add it to Claude, ChatGPT, or any other MCP-compatible AI platform as a custom connector.
4. Authenticate once via OAuth while logged in as an Administrator – most platforms register themselves automatically from the discovery URLs on the Connection tab.
5. The AI can now use any enabled tool group against your site. Revoke any connected app at any time from the Connection tab.

= Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* An MCP-compatible AI platform (Claude, ChatGPT, or similar) to connect to
* No WindCodex account, API key, or license required to use this plugin itself – see **External services** below for what it connects to and why

= Privacy =

This plugin has no code-execution capability of any kind – that's not present anywhere in the codebase. It does not collect data for WindCodex or sell any data. All tool calls run locally against your own site's database through WordPress's normal APIs. The plugin does send data externally in two specific, limited cases – see **External services** below for exactly what's sent, when, and to whom: (1) whatever a specific tool returns, sent only to the AI platform you've explicitly connected via OAuth and only when that platform calls that tool, and (2) a plugin slug (no site or user data) sent to the WordPress.org API only when the plugin-staleness tool is used. By default, deleting the plugin leaves all data in place; opt into full cleanup via **Settings > WindCodex Ops > General > Data & Privacy**.

== External services ==

This plugin connects to the following external services:

**The AI platform you connect (e.g. Claude, ChatGPT, or any other MCP-compatible client)**

The entire purpose of this plugin is to let an AI platform you choose to connect – over the OAuth connector you set up yourself in **Settings > WindCodex Ops > Connection** – call a fixed list of tools against your site. When that platform calls an enabled tool, this plugin sends back only what that specific tool returns (for example: post/page content and metadata, media URLs, SEO fields, or site-diagnostic data such as WordPress/PHP versions or database size), and only in direct response to that tool call. Nothing is sent proactively, on a schedule, or to any platform you haven't explicitly connected and authorized via OAuth. Because you choose which platform to connect, its terms and privacy policy are the platform's own – for example, Anthropic's (https://www.anthropic.com/legal/consumer-terms and https://www.anthropic.com/legal/privacy) or OpenAI's (https://openai.com/policies/terms-of-use and https://openai.com/policies/privacy-policy).

**WordPress.org Plugin API (api.wordpress.org)**

The "Check Plugin Staleness" tool (`wp_check_plugin_staleness`), when called by your connected AI, looks up each active plugin's last-updated date from `https://api.wordpress.org/plugins/info/1.0/{slug}.json` to flag plugins that look abandoned. Only the plugin's slug (its folder/file name) is sent – no site data, user data, or content. This call only happens when that specific tool is invoked. See the WordPress.org API's terms (https://wordpress.org/about/privacy/) for how WordPress.org handles requests to its services.

== Installation ==

= From your WordPress dashboard =

1. Go to **Plugins > Add New**.
2. Search for **WindCodex Ops**.
3. Click **Install Now**, then **Activate**.

= Manual installation =

1. Download the plugin ZIP file.
2. Upload the `windcodex-ops` folder to `/wp-content/plugins/`.
3. Activate through the **Plugins** screen in WordPress.

= After activation =

1. Go to **Settings > WindCodex Ops**.
2. Review which tool groups are on – everything is enabled by default; turn off anything you'd rather a connected AI not touch.
3. Copy the Connector URL from the Connection tab and add it to your AI platform as a custom connector.

== Frequently Asked Questions ==

= Does this plugin phone home or require an account? =

No license key or WindCodex account is required. The plugin does talk to external services in two specific cases – see **External services** above – but only to the AI platform you explicitly connect over the OAuth connector you set up yourself, and to the WordPress.org API when the plugin-staleness tool is used.

= Can the AI run arbitrary code or SQL through this plugin? =

No. WindCodex Ops has no code-execution capability of any kind – not client-side, not server-side. The AI can only call the fixed, pre-approved list of tools this plugin exposes.

= How does authentication work? =

OAuth only. Most platforms register themselves automatically via the discovery URLs shown on the Connection tab. If auto-registration fails, paste the Client ID/Secret from that same tab into the platform's Advanced/OAuth settings instead. The Connection tab lists every app that's ever connected, who it's currently acting as, and lets you revoke any one of them individually.

= Who can connect an AI app? =

Only Administrators, by default. Anyone else who reaches the consent screen is told their account isn't allowed to connect. To let Editors connect too, change **Settings > WindCodex Ops > General > Who can connect**. Each connection acts with the permissions of the person who approved it – there is no shared or fallback account – and stops working immediately if that person loses the role or is deleted. An Editor's connection can only do what an Editor can do in the dashboard.

= Which AI platforms can connect to WindCodex Ops? =

Any platform that supports the Model Context Protocol (MCP) as a client, including Claude and ChatGPT. Connection uses standard OAuth, so most platforms auto-register themselves from the discovery URLs on the Connection tab.

= Can I undo a change the AI made? =

Yes, for any write tool that supports it: post/page edits and deletes, and category/tag edits and deletes. WindCodex Ops keeps an undo window (72 hours by default, extendable to 96 or 168 hours under **Settings > WindCodex Ops > General > Preferences**) so a mistaken edit can be rolled back instead of manually fixed. Note: WordPress has no trash for categories or tags, so undoing a deleted term recreates it from its saved name/slug/description rather than restoring it in place – posts that had it aren't automatically reassigned. "Show activity feed" and "Keep undo history" are separate toggles in **Settings > WindCodex Ops > General > Preferences** – turning off the feed doesn't affect undo.

= Will this work with any SEO plugin? =

Yes. The SEO tools auto-detect Yoast SEO, Rank Math, All in One SEO, and SEOPress, and read/write the correct plugin-specific meta fields automatically – no manual configuration needed.

= Can I turn off specific tool groups? =

Yes. Every one of the 5 tool groups can be switched off independently in **Settings > WindCodex Ops > Tools**, regardless of its risk level. Everything is on by default.

= Does this plugin affect site performance? =

No. Tools only run when an AI platform actively calls them through the connector; there is no background processing or scheduled task running on every page load.

= What happens to my data if I uninstall the plugin? =

By default, all plugin data (settings, connected apps) is left in place in case you reinstall later. If you want everything removed, opt into full cleanup under **Settings > WindCodex Ops > General > Data & Privacy** before uninstalling.

= Is my data sent to WindCodex or any third-party server? =

Nothing is ever sent to WindCodex, and this plugin does not collect or sell any data. Data is sent externally only as described in **External services** above: to the AI platform you've explicitly connected via OAuth (only what an enabled tool returns, only when that platform calls it), and a plugin slug to the WordPress.org API when the plugin-staleness tool is used. Every public, unauthenticated endpoint (OAuth token exchange, dynamic client registration) is rate-limited per IP.

== Screenshots ==

1. Settings > WindCodex Ops – Connection tab, with the Connector URL, OAuth discovery links, and a list of connected AI platforms.
2. Settings > WindCodex Ops – Tools tab, showing every tool group with its risk badge and on/off toggle.
3. Settings > WindCodex Ops – General tab, including "Who can connect" and the Data & Privacy uninstall cleanup option.
4. Settings > WindCodex Ops – Activity tab, showing the log of AI actions with what ran, when, and whether it succeeded.

== Changelog ==

= 1.1.1 =
* New: "Require preview before high-risk actions" now works. Actions that can't be undone – permanently deleting media, recompressing an image, deleting a category or tag, removing a menu item, navigation item or widget – first return a preview and only run when the AI calls again with confirm=true.
* New: "Email me on high-risk actions" now emails the site admin address whenever a high-risk action runs (including confirmed bulk changes), limited to 10 emails an hour.
* New: "Weekly activity summary" now emails a weekly digest of tool calls, changes and high-risk actions. Weeks with no activity are skipped.
* Tweak: clearer descriptions for these settings and for "Show activity feed".
* New: Help menu in the WindCodex Ops settings header, with quick links to the documentation, the support forum, and leaving a review.
* New: "Settings" and "Docs" links on the Plugins screen.

= 1.1.0 =
* New: "Who can connect" setting under Settings > WindCodex Ops > General – Administrators only by default, or Editors and above. Only those users can approve an AI connection on the consent screen.
* Security: a connection now stops working as soon as the user who approved it loses that role or is deleted, and every tool call requires it.
* Removed: the "Acting user" setting. Every connection runs as the person who approved it – there is no shared or fallback account.
* Removed: the custom delete confirmation on the Plugins screen (WordPress's standard one is shown instead), the relabelling of this plugin's row on the Enable Abilities for MCP settings screen, and the script that moved other plugins' notices on the WindCodex Ops settings page. Notices now appear above the settings header.

= 1.0.0 =
Initial release of WindCodex Ops as its own standalone, entirely free plugin covering content, media, SEO, site structure, and site health.

* Split the old "Log all AI activity" toggle into two independent settings: "Show activity feed" (the visible Activity tab, purely cosmetic) and "Keep undo history" (the undo safety net). Turning off the feed no longer disables undo.
* Moved the "Undo window" setting next to "Keep undo history" in Preferences so the two related controls sit together.
* Extended the undo/restore system to categories and tags (`wp_update_category`, `wp_delete_category`, `wp_update_tag`, `wp_delete_tag`). Note: a deleted term is recreated from its saved fields rather than restored in place, since WordPress has no trash for taxonomy terms.
* The Plugins screen's delete confirmation now correctly skips itself when WindCodex Ops Pro is active, since deleting this plugin in that case never touches any data (Pro owns the shared settings and tables).

== Upgrade Notice ==

= 1.1.1 =
High-risk previews, email alerts on high-risk actions and the weekly activity summary now work. Also adds a Help menu to the settings page, plus Settings and Docs links on the Plugins screen.

= 1.1.0 =
Adds "Who can connect" (Administrators only by default). AI connections approved by an Editor or lower role stop working until you allow Editors in Settings > WindCodex Ops > General, or reconnect as an Administrator.

= 1.0.0 =
Initial release – no upgrade steps required.
