=== Content Studio ===
Tags: content, articles, seo, blog
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes the articles you approve in Content Studio on your site, with an overview page, SEO metadata and per-language URLs.

== Description ==

Content Studio writes articles for your site. This plugin brings the ones you approve in as normal posts, gives them an overview page with pagination and per-language URLs, writes their SEO metadata, shows the author under each article and lists articles under their hub article.

Once the site is connected, Content Studio sends each article the moment it is ready. Otherwise the plugin fetches new articles every 15 minutes.

The developer guide in README.md covers configuration, overriding views, hooks and constants.

== External services ==

This plugin connects to the Content Studio Engine at engine.content-studio.com, which provides the articles.

* Syncing: the plugin sends the API key and project ID from its settings to fetch articles and project details, downloads the articles' images, and reports back which articles are live and at which URL.
* Connecting: the plugin sends the site's webhook address, so Content Studio can announce new articles.
* Tracking: on article pages, the visitor's browser reports page views and link clicks to the Engine. Turn this off with the CONTENT_STUDIO_TRACKING_ENABLED constant.

== Changelog ==

= 1.1 =
* Content Studio sends articles to the site as soon as they are ready.
* Author box under articles, styling for pull-quotes, hreflang without a multilingual plugin.

= 1.0 =
* First release.
