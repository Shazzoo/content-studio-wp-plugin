# Content Studio for WordPress

Publishes the articles you approve in the Content Studio Engine on a
WordPress site. The plugin syncs them in as normal posts, gives them an
overview page with pagination and per-language URLs, writes their SEO
metadata, lists the articles under their hub article, reports page views back
to the Engine and confirms to the Engine once an article is live.

This README is for whoever installs the plugin on a site or adapts it for one.

## Requirements

- WordPress 5.6 or newer and PHP 7.0 or newer. That is what the code needs;
  it has been tested on current versions.
- Pretty permalinks (anything but *Plain* under **Settings → Permalinks**).
  The blog, language and page URLs are rewrite rules.
- Outgoing HTTPS to the Engine, for the sync and for downloading images.
- WP-Cron, or a real cron job that calls it (see [Syncing](#syncing)).

## Setup

Activate the plugin, then fill in **Settings → Content Studio**. The page has
four groups.

| Setting | Meaning |
|---|---|
| **Connection Settings** | |
| API Key | Your Content Studio Engine API key |
| Project ID | The Engine project to pull articles from |
| **Blog Settings** | |
| Blog URL | The word in the blog's URLs, default `blog`: `/blog`, `/nl/blog`, `/nl/blog/{article}`. Under *Advanced*, a language can get its own word. See [URLs](#urls) |
| Articles per Page | Articles on the overview before it paginates (1–48, default 10) |
| Article Category | Category for synced articles. Empty uses `Artikelen` on a Dutch site, `Articles` otherwise |
| Fallback Author | WordPress user for articles that arrive without an author |
| **Language Settings** | |
| Published Languages | Which of the project's languages go live. Default: only the main language. See [Languages](#languages) |
| Default Language | The language `/blog` and the Latest Posts block show. The main language is preselected |
| Language Code in URLs | Whether the default language's URLs carry its code too (`/nl/blog`) or not (`/blog`). Only with more than one published language |
| **Style Settings** | Colours, sizes, spacing and rounding of the article cards, with a live preview and **Reset to Defaults** |

The card at the top of the page shows the **sync status**: the number of
synced articles, when the last sync ran and what it did or why it failed, when
the next one is due (with a warning when WP-Cron has stopped running), and the
last failed publish confirmation. **Sync Articles** there runs a sync now.

### The overview page

The overview is a normal WordPress page at the Blog URL that contains the
shortcode `[content_studio_blog]`. The plugin creates it on activation, titled
"Content Studio Articles", and again whenever the Blog URL, the published
languages or the post type change and the page is missing.

- If a page already exists at the Blog URL, the plugin uses that one, also when
  it is a page of the site's own without the shortcode. On a site with its own
  `/blog` page, change the Blog URL straight after activating (see
  [Installing on an existing site](#installing-on-an-existing-site)).
- Rename the page or add text around the shortcode freely: the plugin never
  changes the title or content afterwards. The title shows in the browser tab
  and in search results; the heading above the article list comes from the
  plugin and follows the language ("Artikelen", "Articles").
- With Polylang every published language has its own overview page (see
  [Languages](#languages)).

## Installing on an existing site

The plugin is installed as a zip through the WordPress admin.

### 1. Build the zip

From the plugin's git repository:

```bash
git archive --format=zip --prefix=content-studio/ -o ~/Desktop/content-studio.zip main
```

The folder inside the zip must be called `content-studio`. WordPress tells
plugins apart by their folder, so a zip with another folder name, such as
GitHub's *Download ZIP* (`content-studio-wp-plugin-main/`), installs as a
second copy next to the first. `git archive` also leaves out the `.git`
folder.

### 2. Before installing

- **Make a backup** of the database. The plugin creates posts, pages, users
  and media, and can turn articles into drafts (see [Languages](#languages)).
- **Staging or local copy?** Set `WP_ENVIRONMENT_TYPE` to `staging` or `local`
  in `wp-config.php` first. Without it WordPress assumes production, and the
  plugin then marks the project's articles as published in the Engine, after
  which the live site no longer receives them. See
  [Local development and staging](#local-development-and-staging).
- **Does the site have a page at `/blog`?** The plugin uses whatever page is at
  the Blog URL as its overview. If `/blog` is taken, set the Blog URL to a free
  word straight after activating, before the first sync (step 4).
- **Own posts?** Synced articles become normal posts, so they show up in the
  site's own blog, feed and categories. To keep them apart, set an **Article
  Category**, or let a developer store them under their own post type before
  the first sync (see
  [Storing articles under your own post type](#storing-articles-under-your-own-post-type)).
- **Polylang?** Make sure it has the languages the site should publish, with
  the same codes as the Engine (`nl`, `en`, ...).
- **Permalinks** must not be *Plain* (**Settings → Permalinks**).

### 3. Upload and activate

1. **Plugins → Add New Plugin → Upload Plugin**, choose `content-studio.zip`,
   **Install Now**.
2. **Activate Plugin**. This creates the overview page and schedules the sync.

### 4. Configure, in this order

Under **Settings → Content Studio**:

1. **Connection Settings**: API Key and Project ID. **Save Settings**.
2. **Blog Settings**: the Blog URL (change it now if `/blog` is taken or
   another word suits the site better), Articles per Page, Article Category
   and Fallback Author.
3. **Save Settings**, then **Sync Articles** at the top of the page. Until
   now only the project's main language is published.
4. **Language Settings**: the project's languages are listed now. Tick the
   ones the site should publish and choose the default language. **Save
   Settings**.
5. Adjust **Style Settings** with the live preview.

### 5. Check

- The status card shows the last sync without an error, and the next one as
  due within 15 minutes.
- The overview (`/blog` or your Blog URL) lists the articles, and an article
  opens.
- With Yoast: the article's canonical is its own URL.
- With Polylang: every published language has an overview page under
  **Pages**.
- On a quiet site, set up a real cron job (see [Syncing](#syncing)).
- With a Content-Security-Policy, allow `connect-src
  https://engine.content-studio.com` for [tracking](#tracking).

### Updating

Build a new zip the same way and upload it again through **Plugins → Add New
Plugin → Upload Plugin**. WordPress asks whether to replace the installed
version: choose **Replace current with uploaded**. Settings, articles and
pages stay; the plugin rebuilds its URLs on the next page load. Raise
`Version:` at the top of `content-studio.php` for each release, so the
replace screen shows which version is which.

Read the commits since the last update first: some changes apply to the
existing articles straight away, such as a different default for the
published languages.

## Syncing

WP-Cron syncs every 15 minutes. **Sync Articles** on the settings page syncs
immediately, as does `POST /wp-json/content-studio/v1/sync` for a user with
`manage_options`. All three record their result in the sync status.

WP-Cron only runs when the site gets visitors. On a quiet site, switch it to
a real cron job so syncs run on time:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```
*/5 * * * * curl -s https://example.com/wp-cron.php > /dev/null
```

### What a sync does

The sync first fetches the project from the Engine, for its languages and
main language. Then it asks for content with status `approved`, page by page,
and for each article that has an ID and a title it:

1. creates the post, or updates the one with the same Engine ID;
2. sets title, body, excerpt (the Engine's excerpt, else its meta
   description), slug, publication date, author and category. An article in a
   language the site does not publish becomes a draft (see
   [Languages](#languages));
3. downloads the featured image into the media library, and the diagrams and
   illustrations in the body into the uploads folder, replacing their
   placeholders with the local URLs. An image is only downloaded again when
   its Engine URL changes;
4. stores the Engine fields as post meta (see
   [Article data](#article-data-in-your-own-templates));
5. with Polylang or WPML, assigns the language and links the translations;
6. confirms the article to the Engine, when it is published and the site is
   production (see [`CONTENT_STUDIO_CONFIRM_PUBLISHED`](#constants)).

Once confirmed, the Engine marks the article published and leaves it out of
the next syncs. Confirmation also runs when an editor publishes a synced
article by hand, and when the plugin publishes the drafts of a language that
is published again.

When a confirmed article's URL changes, for example after a change to the
Blog URL, the plugin offers the new URL the next time the post is saved. The
Engine does not accept URL updates for published articles yet (see
[Known limitations](#known-limitations)); the plugin treats that answer as
"already published", not as a failed confirmation.

The body passes through `wp_kses_post`, so anything WordPress does not allow
in post content, such as `<script>` or `<iframe>`, is stripped.

If the post type the articles use is not registered (see
[Storing articles under your own post type](#storing-articles-under-your-own-post-type)),
the sync stops with an error instead of storing articles somewhere they would
not show.

### Authors

Each article's `author_name` is looked up as a WordPress user, by login and
then display name. If there is none, the sync **creates a user** with the role
*Author* and a random password. Articles without an author get the fallback
author from the settings. Imported featured images belong to a user called
*Content Studio*, which is created when needed.

`author_name` can hold several names (`Anna, Bram`). The post then gets one
user with that whole string as its name; the JSON-LD lists the names
separately.

The Engine puts an author box with the author's role and experience at the
top of the article body itself, so it arrives with the text.

## URLs

With one published language there is no language code in the URLs:

| URL | What |
|---|---|
| `/blog` | Overview |
| `/blog/page/2` | Next page of the overview |
| `/blog/{slug}` | An article |

With more than one, each language gets its code: `/nl/blog`,
`/nl/blog/page/2`, `/nl/blog/{slug}`, `/en/blog`, and so on. Switch off
**Language Code in URLs** and the default language goes without it
(`/blog/{slug}`) while the others keep theirs (`/en/blog/{slug}`).

Every overview page and article has one URL, and the other forms redirect
(301) to it, keeping any query string such as utm parameters. So after
switching to one language `/nl/blog/{slug}` redirects to `/blog/{slug}`, with
codes on `/blog` redirects to `/nl/blog`, and an article requested under
another language's URL moves to its own. The exception is **Default
Language** set to *All published languages*: `/blog` then shows every
published language on one overview.

A language code the site does not publish returns a 404, as does a page
number past the last page. Article URLs follow the permalink setting for the
trailing slash.

### Changing the Blog URL

`blog` is the **Blog URL** setting. Changing it to `kennis` gives `/kennis`,
`/nl/kennis` and `/nl/kennis/{article}`. On a change the plugin:

- renames its overview page to the new URL, so there is never a second one;
- remembers the old URL and redirects it (301) to the same path under the new
  one: `/blog` → `/kennis`, `/nl/blog/page/2` → `/nl/kennis/page/2`,
  `/nl/blog/{article}` → `/nl/kennis/{article}`. Without a language code only
  the overview and its pages redirect, because `/blog/{something}` can be the
  site's own posts;
- rebuilds the rewrite rules, and with Yoast its stored URLs, on the next page
  load.

It refuses a URL that a page already uses (other than its own overview),
two-letter URLs (they look like language codes) and paths WordPress reserves,
such as `/page` and `/wp-json`, and keeps the old value with an error message.

The site's permalink setting (**Settings → Permalinks**) does not change the
Blog URL. With [your own post type](#storing-articles-under-your-own-post-type)
the URL is that post type's slug, and the setting is read-only.

### A different URL per language

Under **Blog URL**, *Advanced: a different URL per language* gives a language
its own word, for example `kennis` as the Blog URL and `knowledge` for
English: `/nl/kennis/{article}` and `/en/knowledge/{article}`. A language left
empty uses the Blog URL. It works with and without language codes: with the
code off for an English default language, English lives at `/knowledge` and
Dutch at `/nl/kennis`.

The overview is still one page, at the Blog URL; every language's URL shows
it in that language. A language under another language's word redirects to
its own (`/en/kennis` → `/en/knowledge`), and a removed or changed word
redirects like an old Blog URL. The same checks apply as for the Blog URL.

With Polylang it is the address of the language's own overview page (see
[Languages](#languages)). It is not available with WPML, or for a custom post
type, which has one slug.

## Languages

An Engine project can generate articles in several languages while the site
publishes only some of them. **Published Languages** decides which go live;
by default only the project's main language. The list of languages comes from
the Engine, fetched at every sync; a language the Engine adds later appears
unticked.

Articles in other languages are still synced, but as drafts: they are not on
the site, not in the sitemap, feed or search, and not confirmed to the
Engine, so the Engine keeps offering them. Tick a language later and its
articles go live, and are confirmed, on the next page load. Changing the
setting applies straight away to articles already on the site, including
after an update from a version without this setting: on such a site the
articles outside the main language become drafts.

The plugin only puts back drafts it made itself (marked with
`_content_studio_unpublished_language`); a draft an editor made stays a
draft.

**Default Language** is the language `/blog` and the Latest Posts block show.
Until the setting is saved, it is the Engine project's main language, and
follows it when the Engine changes it. Until the Engine has reported a main
language, the plugin uses the most common language among the synced articles,
and failing that the site language. A default language that is not published
falls back to the first published one. *All published languages* shows every
published language on one overview.

### Without a multilingual plugin

The plugin handles the language URLs itself (see [URLs](#urls)) and offers a
language switcher through `[content_studio_language_switcher]`, which only
appears with more than one published language.

### With Polylang or WPML

That plugin owns the language URLs and the switcher, **Language Code in URLs**
does not apply, and **Published Languages** defaults to every language. The
sync assigns each article its language and links translations that share a
`cluster_key`. Articles are only linked when each language occurs once in the
group; otherwise the `cluster_key` is a topic cluster rather than a set of
translations. When Polylang or WPML is switched on after articles were
synced, the plugin gives those articles their language once.

Polylang and WPML must have translation switched on for the post type the
articles use, and the languages must use the Engine's codes (`nl`, `en`).

With **Polylang**:

- Each published language gets its own overview page, labelled with that
  language and linked as a translation of the default one, because Polylang
  takes a page's language from the page itself. The plugin creates them when a
  language is published. Free Polylang wants a unique address per page, so a
  language's page lives at its URL from *Advanced: a different URL per
  language*, or else at `{Blog URL}-{language}`, e.g. `/en/blog-en`.
- Changing that URL moves the page, but the old address does not redirect.
- Articles get WordPress's own URLs (**Settings → Permalinks**), with
  Polylang's language prefix, not the Blog URL.
- Switching Polylang off leaves the extra overview pages behind; trash them.

WPML has not been tested. It uses one overview page.

## Shortcodes and block

| | |
|---|---|
| `[content_studio_blog]` | The paginated overview. Used on the overview page |
| `[content_studio_language_switcher]` | Links to the overview in each published language. Renders the Polylang or WPML switcher when one of those is active |
| **Content Studio Latest Posts** block | The newest articles in the default language anywhere on a page. Settings: number of posts (max 12), title, excerpt, meta, image |

## Hub articles

When the Engine groups articles under a hub article, the hub's page lists the
published articles that belong to it under its text, in a "Cluster overview"
section in the hub's language. Articles synced before the plugin stored
`hub_content_id` only get it when the Engine sends them again. Override
`hub-articles.php` to change the section.

## Overriding views

Every front-end view can be replaced from the theme. Put a file with the same
name in `content-studio/` in the (child) theme; anything the theme does not
provide keeps coming from the plugin, so plugin updates still reach the views
you did not touch.

```
wp-content/themes/your-theme/content-studio/article-card.php
```

| View | Variables | Purpose |
|---|---|---|
| `article-card.php` | `$card` | One article card |
| `article-list.php` | `$attributes`, `$posts` (`WP_Query`), `$pagination` | Article overview, for the block and the overview page |
| `pagination.php` | `$pagination` | Page links under the overview |
| `hub-articles.php` | `$title`, `$intro`, `$articles` (`WP_Query`), `$attributes` | Articles under a hub article |
| `language-switcher.php` | `$languages` | The language switcher without Polylang or WPML |

Copy the plugin's version from `views/` as a starting point; the docblock at
the top of each file describes its variables. Inside a loop over `$posts` or
`$articles`, `Content_Studio_Latest_Posts_Block::render_card($attributes)`
renders one card through `article-card.php`, so an overridden card is used
there too. Front-end text comes from `Content_Studio_Strings::get()`, see
[`content_studio_strings`](#content_studio_strings).

**`$card`** in `article-card.php`:

| Key | |
|---|---|
| `title`, `title_url` | Article title and URL |
| `excerpt` | Excerpt, or the first 25 words of the body |
| `meta_items` | Date and reading time, as strings |
| `image_html` | `<img>` of the featured image, or `''` |
| `image_url`, `image_label` | Image link URL and its label for screen readers |
| `placeholder_text` | Text in the placeholder when there is no image |
| `show_image`, `show_title`, `show_meta`, `show_excerpt` | What the block settings switched on |
| `title_disabled` | `true` in the admin preview, where the title is not a link |
| `article_classes` | `post_class()` classes for the `<article>` |

Inside the card, the usual template tags (`get_the_ID()`, `get_the_title()`,
`get_post_meta()`) work for the current article, so an override can show any
[article field](#article-data-in-your-own-templates), such as the author.

**`$attributes`** holds `title`, `showTitle`, `showImage`, `showMeta`,
`showExcerpt` and `postsToShow`, as set on the block or by the overview page.

**`$pagination`** holds `current`, `total`, `previous_url`, `next_url` and
`pages`: per page a `number`, `url` and `current` flag, with `null` marking a
gap. `previous_url` and `next_url` are `''` on the first and last page.

**`$languages`** is a list with, per language, `locale` (e.g. `nl`), `url`
(the overview in that language) and `current`.

Example: an article card with the author under the title.

```php
<?php // wp-content/themes/your-theme/content-studio/article-card.php ?>
<article class="<?php echo esc_attr($card['article_classes']); ?>">
    <?php if ($card['show_image'] && '' !== $card['image_html']) : ?>
        <a href="<?php echo esc_url($card['image_url']); ?>"><?php echo $card['image_html']; ?></a>
    <?php endif; ?>

    <h2><a href="<?php echo esc_url($card['title_url']); ?>"><?php echo esc_html($card['title']); ?></a></h2>

    <p class="byline"><?php echo esc_html(get_post_meta(get_the_ID(), '_content_studio_author_name', true)); ?></p>

    <?php if ($card['show_excerpt']) : ?>
        <p><?php echo esc_html($card['excerpt']); ?></p>
    <?php endif; ?>
</article>
```

> **Override only what you change.** An overridden view no longer receives
> fixes from plugin updates.

The admin preview on the settings page always uses the plugin's own card.

### Styling without overriding

Card colours, sizes and spacing come from the style settings in the admin.
The plugin prints those as inline CSS, so setting its CSS custom properties
from the theme has no effect. For anything the settings do not cover, target
the classes in the views from the theme's stylesheet, for example
`.content-studio-blog__article` or `.content-studio-pagination__link`, or
override the view.

## Article data in your own templates

Synced articles are normal posts: title, content, excerpt, author, date and
featured image work as usual. The Engine's own fields are stored as post meta
named `_content_studio_{field}`:

```php
$author = get_post_meta(get_the_ID(), '_content_studio_author_name', true);
```

| Field | |
|---|---|
| `external_id` | The article's Engine ID. Present on every synced article, so it also tells you whether a post came from the Engine |
| `locale` | Language code, e.g. `nl` |
| `status`, `type`, `channel`, `content_type` | The Engine's status and kind of content |
| `seo_title`, `meta_description` | SEO title and description |
| `og_title`, `og_description`, `twitter_title`, `twitter_description` | Social titles and descriptions |
| `image_url`, `featured_image_alt` | The featured image's Engine URL and alt text |
| `author_name` | Author, or several separated by `,` or `;` |
| `author_role_title`, `author_experience_label`, `author_experience_summary`, `author_article_relevance`, `author_boundary_note` | Author details |
| `cluster_key`, `hub_content_id` | Topic cluster, and the Engine ID of the hub article this one belongs to |
| `primary_keyword`, `funnel_stage`, `intent`, `angle`, `source_month` | Editorial metadata |
| `published_at`, `date`, `generated_at`, `planned_at`, `updated_at` | Engine dates |
| `content_hash` | The Engine's hash of the content |
| `raw_payload` | The full article as the Engine sent it, as JSON |

The plugin keeps its own bookkeeping in post meta too:

| Meta | |
|---|---|
| `_content_studio_published_confirmed_at`, `_content_studio_published_confirmed_url` | Last confirmation to the Engine, and the URL it confirmed |
| `_content_studio_unpublished_language` | The plugin made this article a draft because its language is not published |
| `_content_studio_image_import_error` | Why the featured image failed to download |
| `_content_studio_imported_*` | Which image URLs are already downloaded |

## SEO

Without an SEO plugin, article pages get:

- the Engine's `seo_title` as page title;
- a meta description;
- Open Graph and Twitter tags, including the featured image;
- `BlogPosting` JSON-LD.

The overview gets `Blog` JSON-LD listing the articles on that page. Every
overview language and page has its own canonical.

**Yoast SEO** takes over the tags and schema. The plugin then hands Yoast the
Engine's values: the SEO title as `%%title%%`, the meta description, and the
Open Graph and Twitter texts. A value an editor fills in in Yoast always wins.
It also fills `%%page%%` on the overview pages, and gives each overview
language and page its own canonical, `og:url` and schema URL. Yoast stores
each article's URL; when the plugin's URL settings change, it empties those so
Yoast stores the new ones on the next visit.

**Rank Math, All in One SEO and SEOPress** are detected, and the plugin then
outputs no tags of its own, but it does not pass them the Engine's values yet.

## Tracking

Article pages load `js/tracking.js`, which reports page views and link clicks
to the Engine. It is a separate file, configured through data attributes on
`#content-studio-tracking`, so it works under a Content-Security-Policy that
blocks inline scripts. Only synced articles are tracked.

## Hooks

### `content_studio_post_type`

The post type articles are stored as. Default `post`. See
[Storing articles under your own post type](#storing-articles-under-your-own-post-type).

### `content_studio_output_meta_tags`

Whether the plugin writes its own meta tags and JSON-LD. Default: `true`,
or `false` when Yoast, Rank Math, All in One SEO or SEOPress is active.

```php
// Keep the plugin's tags even with an SEO plugin active.
add_filter('content_studio_output_meta_tags', '__return_true');
```

### `content_studio_strings`

The text visitors see, per language: the overview's heading, "No articles
yet...", the reading time, the hub section and the labels of the pagination
and language switcher. The plugin ships English, Dutch and German, and picks
the language of the article (cards, hub section) or of the overview being
shown. Any other language falls back to English. WordPress's own translation
files follow the site language rather than the article, which is why the
plugin keeps these itself.

```php
add_filter('content_studio_strings', function ($strings) {
    // Change one text.
    $strings['nl']['blog_title'] = 'Kennisbank';

    // Add a language; missing keys fall back to English.
    $strings['fr'] = [
        'blog_title' => 'Articles',
        'no_articles' => 'Pas encore d\'articles...',
        'read_time' => '%d min de lecture',
    ];

    return $strings;
});
```

The keys are in `includes/class-strings.php`. `read_time` takes the minutes
as `%d`.

### `content_studio_display_locale`

The language the overview shows for the current request, as a two-letter
code, or `all` to show every published language. Only without Polylang or
WPML; with them the current language comes from that plugin.

```php
add_filter('content_studio_display_locale', fn ($locale) => 'en');
```

## Constants

Set these in `wp-config.php`.

| Constant | Default | Meaning |
|---|---|---|
| `CONTENT_STUDIO_API_ROUTE` | `https://engine.content-studio.com/api/v1` | Engine API base URL |
| `CONTENT_STUDIO_CONFIRM_PUBLISHED` | `true` on production | Whether to confirm published articles to the Engine. Defaults to `WP_ENVIRONMENT_TYPE === 'production'`, so a local or staging copy pointing at the same Engine does not mark real content as published |
| `CONTENT_STUDIO_TRACKING_ENABLED` | `true` | `false` turns tracking off |
| `CONTENT_STUDIO_TRACKING_ENDPOINT` | `https://engine.content-studio.com/api/tracking/event` | Where tracking events go. Must be an absolute URL; anything else falls back to the default |

## Storing articles under your own post type

By default articles are posts. That suits most sites: themes, feeds, search
and SEO plugins all handle posts. A site that must keep the Engine's articles
apart from its own blog can store them under its own post type instead.

**Decide this before the first sync.** Articles that were already synced stay
where they are: switching later makes the next sync create every article
again under the new type, while the old ones stay behind as posts.

Register the post type in the theme or a small site plugin, and point the
plugin at it:

```php
add_action('init', function () {
    register_post_type('kennisbank', [
        'label' => 'Kennisbank',
        'public' => true,
        'has_archive' => false,
        'rewrite' => ['slug' => 'kennisbank', 'with_front' => false],
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'author'],
        'show_in_rest' => true,
    ]);
});

add_filter('content_studio_post_type', fn () => 'kennisbank');
```

The plugin then:

- stores new articles as `kennisbank`;
- uses the post type's rewrite slug instead of the Blog URL setting:
  `/kennisbank`, `/nl/kennisbank`, `/nl/kennisbank/{slug}`;
- creates the overview page at `/kennisbank`, and rebuilds the rewrite rules.

Three things to get right in the registration:

- **`'with_front' => false`.** Otherwise WordPress puts the permalink prefix
  in front: with permalinks set to `/blog/%postname%`, articles would live at
  `/blog/kennisbank/...`.
- **`'has_archive' => false`.** The overview is the plugin's page at the same
  slug; an archive would claim that URL.
- **The `supports` list above.** Without `thumbnail` the featured image is
  stored but most themes will not show it; without `excerpt` and `author` the
  cards lose their excerpt and author.

Categories only apply when the post type has the `category` taxonomy. A
different URL per language is not available for a custom post type.

## Local development and staging

- **Set `WP_ENVIRONMENT_TYPE`.** WordPress assumes `production` when it is
  not set, and on production the plugin confirms every synced article to the
  Engine. A local or staging copy pointing at a real project would then mark
  that project's articles as published, and they drop out of the live site's
  sync. Use `define('WP_ENVIRONMENT_TYPE', 'local');` (or `staging`), or set
  `CONTENT_STUDIO_CONFIRM_PUBLISHED` to `false`.
- **Tracking still runs** outside production, so local page views reach the
  Engine. Set `CONTENT_STUDIO_TRACKING_ENABLED` to `false` locally.
- **"Discourage search engines"** (**Settings → Reading**) makes Yoast leave
  out the canonical and robots tags. Check SEO output with it off.

## Stored data

Settings live in `wp_options` under `content_studio_*`. The plugin also
stores:

| Option | |
|---|---|
| `content_studio_last_sync_at`, `_last_sync_count`, `_last_sync_error` | Time (Unix timestamp), article count and error of the last sync |
| `content_studio_last_publish_confirmation_error` | Last failed confirmation to the Engine |
| `content_studio_engine_primary_locale`, `_engine_locales` | The project's languages, as the Engine reported them |
| `content_studio_blog_slug`, `content_studio_blog_slugs`, `content_studio_previous_blog_slugs` | The Blog URL, the URLs per language, and the earlier ones that redirect |
| `content_studio_published_locales`, `content_studio_default_locale_in_url` | Published languages, and whether the default language has a code in its URLs |
| `content_studio_applied_published_locales` | The published languages last applied to the existing articles |
| `content_studio_rewrite_version` | When to rebuild the rewrite rules and Yoast's stored URLs |

The settings page shows the sync results in the card at the top.

Deactivating or deleting the plugin removes nothing: posts, pages, images,
users and options stay.

## Troubleshooting

| Symptom | Check |
|---|---|
| No articles arrive | The sync status on the settings page: the last error, and whether the next sync is overdue (WP-Cron not running). **Sync Articles** runs one immediately |
| An article is missing | Is its language under **Published Languages**? Otherwise it is a draft. Only `approved` content syncs. Already-confirmed articles are not fetched again, so a deleted post does not come back on its own |
| The overview gives a 404 | The overview page was deleted or unpublished. Restore it from **Pages → Trash** and publish it, or deactivate and activate the plugin to create a new one |
| Language or page URLs give a 404 | Save **Settings → Permalinks** once to rebuild the rewrite rules. A page number past the last page is a 404 on purpose |
| With Polylang, a language has no overview | Is the language published, and does Polylang have it with the Engine's code? The page is created when the published languages change, so after adding a language to Polylang, untick it under **Published Languages**, save, tick it and save again |
| Featured image missing | `_content_studio_image_import_error` on the post |
| Articles not confirmed in the Engine | `WP_ENVIRONMENT_TYPE`, `CONTENT_STUDIO_CONFIRM_PUBLISHED`, and the last confirmation error in the sync status |

## Known limitations

- **Changes after publication do not sync.** Once an article is confirmed,
  the Engine no longer sends it, so edits made in the Engine afterwards do not
  reach WordPress. Neither does withdrawing or deleting it there.
- **The Engine does not take URL updates.** It only confirms articles that
  are still approved, so after a URL change the Engine keeps the old URL of
  an article it already has as published.
- **No full re-sync.** There is no button or command yet to fetch every
  article again, for example to pick up a field added in a plugin update.
- **An existing page at the Blog URL is taken over** as the overview on
  activation, also when it is not the plugin's own.
- **Dates follow the site language.** The date on a card uses WordPress's
  date format and month names, so a Dutch article on an English site shows
  "August 24, 2026". The same goes for WordPress's own "Page 2" in the title.
- **Rank Math, All in One SEO and SEOPress** do not get the Engine's SEO
  values yet.
- **WPML** has not been tested.

## Code layout

| Path | |
|---|---|
| `content-studio.php` | Bootstrap, style settings, cron, `content_studio_load_view()`, `content_studio_post_type()`, `content_studio_blog_slug()` |
| `includes/class-api-client.php` | Engine API: contents, project, confirm-published |
| `includes/class-storage.php` | The sync: posts, meta, images, authors, published languages |
| `includes/class-language.php` | Languages: published, default, URL codes; Polylang and WPML |
| `includes/class-blog-route.php` | Overview pages, rewrite rules, redirects, pagination, language switcher |
| `includes/class-latest-posts-block.php` | The block and the article overview |
| `includes/class-hub-articles.php` | Articles under a hub article |
| `includes/class-strings.php` | Front-end text per language |
| `includes/class-seo.php` | Meta tags, JSON-LD, Yoast integration |
| `includes/class-tracking.php` | Loads the tracking script |
| `includes/class-publish-confirmation.php` | Confirms published articles to the Engine |
| `includes/class-admin-settings.php`, `includes/class-rest-routes.php` | Settings page and the sync REST route |
| `views/` | Front-end views (overridable) and the admin screens |
| `js/tracking.js`, `css/content-studio.css` | Tracking script and styles |
