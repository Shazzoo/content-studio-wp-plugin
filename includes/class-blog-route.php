<?php

class Content_Studio_Blog_Route
{
    const POSTS_PER_PAGE_OPTION = 'content_studio_articles_per_page';

    const DEFAULT_POSTS_PER_PAGE = 10;

    const MAX_POSTS_PER_PAGE = 48;

    const SLUG_OPTION = 'content_studio_blog_slug';

    const DEFAULT_SLUG = 'blog';

    /**
     * Slugs the blog lived under before, so their URLs keep redirecting.
     */
    const PREVIOUS_SLUGS_OPTION = 'content_studio_previous_blog_slugs';

    /**
     * Per language a URL of its own instead of the Blog URL, e.g. 'en' =>
     * 'knowledge'. Posts on a standalone site only.
     */
    const SLUG_OVERRIDES_OPTION = 'content_studio_blog_slugs';

    const LEGACY_QUERY_VAR = 'cs_legacy_blog';

    const LEGACY_PATH_QUERY_VAR = 'cs_legacy_path';

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [self::class, 'register_rewrites']);
        add_action('init', [self::class, 'maybe_flush_rewrite_rules'], 20);
        add_filter('query_vars', [self::class, 'register_query_var']);
        add_action('add_option_' . self::SLUG_OPTION, [self::class, 'on_slug_added'], 10, 2);
        add_action('update_option_' . self::SLUG_OPTION, [self::class, 'on_slug_changed'], 10, 2);
        add_action('update_option_' . self::SLUG_OVERRIDES_OPTION, [self::class, 'on_slug_overrides_changed'], 10, 2);
        add_filter('post_link', [self::class, 'localize_permalink'], 10, 2);
        add_filter('post_type_link', [self::class, 'localize_permalink'], 10, 2);
        // Before redirect_canonical (10), which would first add a slash.
        add_action('template_redirect', [self::class, 'redirect_legacy_blog_url'], 9);
        add_action('template_redirect', [self::class, 'redirect_to_canonical_url'], 9);
        add_action('template_redirect', [self::class, 'not_found_unknown_language']);
        add_action('template_redirect', [self::class, 'not_found_past_last_page']);
    }

    /**
     * The overview and articles under every URL the blog uses. {slug} is
     * content_studio_blog_slug(): the Blog URL, or a language's own URL.
     *
     * Without a language code: /{slug}/page/{n} and /{slug}/{article}, for the
     * Blog URL and for the URL of a language that has no code. The page rule
     * is explicit because a custom post type's own rules would read
     * /{slug}/page/2 as an article named "page".
     *
     * With a code, standalone only (Polylang and WPML build their own
     * language URLs): /{lang}/{slug}, /{lang}/{slug}/page/{n} and
     * /{lang}/{slug}/{article}. For the Blog URL the code is a generic
     * two-letter pattern, so syncing a new language never requires a rewrite
     * flush; codes that aren't published 404 in not_found_unknown_language().
     * A language's own URL only matches with its own code.
     *
     * Every overview URL renders the one overview page; the language comes
     * from the URL. A URL that is not a page's own redirects to it in
     * redirect_to_canonical_url().
     */
    public static function register_rewrites()
    {
        $page = content_studio_blog_slug();
        $post_type = content_studio_post_type();
        $lang_var = Content_Studio_Language::QUERY_VAR;

        foreach (self::slugs_without_code() as $slug) {
            $pattern = preg_quote($slug, '#');

            add_rewrite_rule('^' . $pattern . '/page/?([0-9]{1,})/?$', 'index.php?pagename=' . $page . '&paged=$matches[1]', 'top');

            if ($slug !== $page) {
                add_rewrite_rule('^' . $pattern . '/?$', 'index.php?pagename=' . $page, 'top');
            }

            // A custom post type has this rule for its own slug already.
            if ('post' === $post_type || $slug !== $page) {
                add_rewrite_rule('^' . $pattern . '/([^/]+)/?$', 'index.php?post_type=' . $post_type . '&name=$matches[1]', 'top');
            }
        }

        foreach (self::previous_slugs() as $previous) {
            $previous_pattern = preg_quote($previous, '#');

            // Only the overview: /{previous}/{anything else} may be the site's
            // own posts, under a permalink structure like /blog/%postname%.
            add_rewrite_rule(
                '^' . $previous_pattern . '(/page/?[0-9]{1,})?/?$',
                'index.php?' . self::LEGACY_QUERY_VAR . '=1&' . self::LEGACY_PATH_QUERY_VAR . '=$matches[1]',
                'top'
            );

            if (Content_Studio_Language::is_standalone()) {
                add_rewrite_rule(
                    '^([a-z]{2})/' . $previous_pattern . '(/.*)?$',
                    'index.php?' . self::LEGACY_QUERY_VAR . '=1&' . self::LEGACY_PATH_QUERY_VAR . '=$matches[2]&' . $lang_var . '=$matches[1]',
                    'top'
                );
            }
        }

        if (!Content_Studio_Language::is_standalone()) {
            return;
        }

        $with_code = ['([a-z]{2})' => $page];

        foreach (self::slug_overrides() as $locale => $slug) {
            $with_code['(' . preg_quote($locale, '#') . ')'] = $slug;
        }

        foreach ($with_code as $code_pattern => $slug) {
            $pattern = preg_quote($slug, '#');

            add_rewrite_rule('^' . $code_pattern . '/' . $pattern . '/?$', 'index.php?pagename=' . $page . '&' . $lang_var . '=$matches[1]', 'top');
            add_rewrite_rule('^' . $code_pattern . '/' . $pattern . '/page/?([0-9]{1,})/?$', 'index.php?pagename=' . $page . '&paged=$matches[2]&' . $lang_var . '=$matches[1]', 'top');
            add_rewrite_rule('^' . $code_pattern . '/' . $pattern . '/([^/]+)/?$', 'index.php?post_type=' . $post_type . '&name=$matches[2]&' . $lang_var . '=$matches[1]', 'top');
        }
    }

    /**
     * Per language its own URL, from the advanced setting. Only for posts on
     * a standalone site: Polylang and WPML translate the page's URL
     * themselves, and a custom post type has a single slug.
     *
     * @return array<string, string> language => slug
     */
    public static function slug_overrides()
    {
        if ('post' !== content_studio_post_type() || !Content_Studio_Language::is_standalone()) {
            return [];
        }

        $global = content_studio_blog_slug();
        $overrides = [];

        foreach ((array) get_option(self::SLUG_OVERRIDES_OPTION, []) as $locale => $slug) {
            $locale = Content_Studio_Language::normalize((string) $locale);
            $slug = sanitize_title((string) $slug);

            if ('' !== $locale && '' !== $slug && $slug !== $global) {
                $overrides[$locale] = $slug;
            }
        }

        return $overrides;
    }

    /**
     * The Blog URL plus the URL of each published language that has no code.
     *
     * @return array<int, string>
     */
    private static function slugs_without_code()
    {
        $slugs = [content_studio_blog_slug()];

        foreach (Content_Studio_Language::published_locales() as $locale) {
            if ('' === Content_Studio_Language::url_prefix($locale)) {
                $slugs[] = content_studio_blog_slug($locale);
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Every URL the blog uses now.
     *
     * @return array<int, string>
     */
    private static function current_slugs()
    {
        return array_values(array_unique(array_merge([content_studio_blog_slug()], array_values(self::slug_overrides()))));
    }

    public static function register_query_var($vars)
    {
        $vars[] = Content_Studio_Language::QUERY_VAR;
        $vars[] = self::LEGACY_QUERY_VAR;
        $vars[] = self::LEGACY_PATH_QUERY_VAR;

        return $vars;
    }

    /**
     * Synced articles get a language-prefixed permalink so every link on the
     * site points at the localized URL. Non-Content-Studio posts are untouched.
     */
    public static function localize_permalink($permalink, $post)
    {
        if (!Content_Studio_Language::is_standalone()) {
            return $permalink;
        }

        if (!$post instanceof WP_Post || content_studio_post_type() !== $post->post_type) {
            return $permalink;
        }

        $locale = Content_Studio_Language::for_post($post->ID);

        if ('' === $locale) {
            return $permalink;
        }

        return home_url(user_trailingslashit(Content_Studio_Language::url_prefix($locale) . '/' . content_studio_blog_slug($locale) . '/' . $post->post_name));
    }

    /**
     * A URL under a previous slug to the same path under the current one,
     * e.g. /nl/blog/page/2 to /nl/kennis/page/2 and /blog to /kennis.
     */
    public static function redirect_legacy_blog_url()
    {
        if ('1' !== (string) get_query_var(self::LEGACY_QUERY_VAR)) {
            return;
        }

        $locale = Content_Studio_Language::normalize((string) get_query_var(Content_Studio_Language::QUERY_VAR));

        if ('' !== $locale) {
            // An unknown language 404s in not_found_unknown_language().
            if (!Content_Studio_Language::is_available($locale)) {
                return;
            }
        } else {
            $locale = Content_Studio_Language::default_locale();
        }

        $path = '/' . trim((string) get_query_var(self::LEGACY_PATH_QUERY_VAR), '/');

        self::redirect(home_url(user_trailingslashit(untrailingslashit(Content_Studio_Language::url_prefix($locale) . '/' . content_studio_blog_slug($locale) . $path))));
    }

    /**
     * Each blog page and article has one URL; every other way to reach it
     * redirects there. So a language code its URLs no longer carry is dropped
     * (/nl/blog/x to /blog/x), the overview without a code goes to its
     * language's URL when that has one (/blog to /nl/blog), and a language
     * reached under another language's URL moves to its own (/en/kennis to
     * /en/knowledge).
     */
    public static function redirect_to_canonical_url()
    {
        if (!Content_Studio_Language::is_standalone()) {
            return;
        }

        $locale = Content_Studio_Language::normalize((string) get_query_var(Content_Studio_Language::QUERY_VAR));

        // An unknown language 404s in not_found_unknown_language().
        if ('' !== $locale && !Content_Studio_Language::is_available($locale)) {
            return;
        }

        if (self::is_blog_page()) {
            // "All languages" shows every language on the URL without a code.
            if ('' === $locale && 'all' === trim((string) get_option(Content_Studio_Language::OPTION, ''))) {
                return;
            }

            self::redirect_if_elsewhere(self::page_url(self::current_page()));

            return;
        }

        if (is_singular(content_studio_post_type())) {
            $post_id = get_queried_object_id();

            if ('' !== Content_Studio_Language::for_post($post_id)) {
                self::redirect_if_elsewhere((string) get_permalink($post_id));
            }
        }
    }

    /**
     * Paths only: a query string such as utm parameters is not a different
     * URL, and redirect() keeps it.
     *
     * @param string $target
     */
    private static function redirect_if_elsewhere($target)
    {
        $target_path = untrailingslashit((string) wp_parse_url($target, PHP_URL_PATH));
        $current_path = untrailingslashit((string) wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH));

        if ('' !== $target && $target_path !== $current_path) {
            self::redirect($target);
        }
    }

    /**
     * A 301 that keeps the query string, such as utm parameters.
     *
     * @param string $url
     */
    private static function redirect($url)
    {
        $query = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : '';

        wp_safe_redirect('' !== $query ? $url . '?' . $query : $url, 301);
        exit;
    }

    /**
     * Problem with using $slug as the blog URL, or '' when it is fine.
     *
     * @param string $slug A sanitized slug.
     *
     * @return string
     */
    public static function slug_error($slug)
    {
        if ($slug === content_studio_blog_slug()) {
            return '';
        }

        if (preg_match('/^[a-z]{2}$/', $slug)) {
            return 'Two-letter URLs are reserved for language codes such as /nl and /en.';
        }

        if (in_array($slug, ['page', 'feed', 'search', 'author', 'category', 'tag', 'comments', 'embed', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json'], true)) {
            return sprintf('/%s is reserved by WordPress.', $slug);
        }

        $page = get_page_by_path($slug);

        if ($page instanceof WP_Post && 'page' === $page->post_type && !has_shortcode((string) $page->post_content, 'content_studio_blog')) {
            return sprintf('The page "%s" already uses /%s. Choose another URL, or give that page a different one first.', get_the_title($page), $slug);
        }

        return '';
    }

    /**
     * The first save of the setting: the blog moves from the default slug.
     */
    public static function on_slug_added($option, $value)
    {
        self::on_slug_changed(self::DEFAULT_SLUG, $value);
    }

    /**
     * Moves the overview page to the new slug, so there is never a second
     * one, and remembers the old slug so its URLs redirect. The rewrite rules
     * follow on the next request: their version includes the slug.
     */
    public static function on_slug_changed($old, $new)
    {
        if ('post' !== content_studio_post_type()) {
            return;
        }

        $old = sanitize_title((string) $old) ?: self::DEFAULT_SLUG;
        $new = sanitize_title((string) $new) ?: self::DEFAULT_SLUG;

        if ($old === $new) {
            return;
        }

        $page = get_page_by_path($old);

        if ($page instanceof WP_Post && 'page' === $page->post_type && has_shortcode((string) $page->post_content, 'content_studio_blog')) {
            wp_update_post([
                'ID' => $page->ID,
                'post_name' => $new,
            ]);
        }

        $previous = array_diff(self::previous_slugs(), [$new]);
        $previous[] = $old;

        update_option(self::PREVIOUS_SLUGS_OPTION, array_values(array_unique($previous)));
    }

    /**
     * @return array<int, string>
     */
    private static function previous_slugs()
    {
        $current = self::current_slugs();

        return array_values(array_filter(
            array_map('sanitize_title', (array) get_option(self::PREVIOUS_SLUGS_OPTION, [])),
            static function ($slug) use ($current) {
                return '' !== $slug && !in_array($slug, $current, true);
            }
        ));
    }

    /**
     * A language URL that is no longer used redirects from now on, like an
     * old Blog URL.
     */
    public static function on_slug_overrides_changed($old, $new)
    {
        $removed = array_diff(
            array_map('sanitize_title', array_values((array) $old)),
            array_map('sanitize_title', array_values((array) $new))
        );

        if ([] === $removed) {
            return;
        }

        update_option(
            self::PREVIOUS_SLUGS_OPTION,
            array_values(array_unique(array_merge((array) get_option(self::PREVIOUS_SLUGS_OPTION, []), $removed)))
        );
    }

    /**
     * A language code the site does not publish is a 404: /de/blog when only
     * Dutch is published.
     */
    public static function not_found_unknown_language()
    {
        if (!Content_Studio_Language::is_standalone()) {
            return;
        }

        $requested = (string) get_query_var(Content_Studio_Language::QUERY_VAR);

        if ('' !== $requested && !Content_Studio_Language::is_available($requested)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    /**
     * /blog/page/50 on a blog with ten pages is a 404, not an empty overview.
     */
    public static function not_found_past_last_page()
    {
        $current = self::current_page();

        if ($current < 2 || !self::is_blog_page()) {
            return;
        }

        if (Content_Studio_Latest_Posts_Block::get_posts(self::posts_per_page(), $current)->have_posts()) {
            return;
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }

    /**
     * The page that renders [content_studio_blog].
     *
     * @return bool
     */
    public static function is_blog_page()
    {
        if (!is_page()) {
            return false;
        }

        return has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'content_studio_blog');
    }

    public static function activate()
    {
        self::ensure_blog_page();
        // init has already run during activation, so the rules are not
        // registered yet.
        self::register_rewrites();
        flush_rewrite_rules();
        update_option('content_studio_rewrite_version', self::rewrite_version());
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }


    /**
     * The overview page lives at the blog slug and renders the shortcode.
     */
    public static function ensure_blog_page()
    {
        $slug = content_studio_blog_slug();
        $page = get_page_by_path($slug);

        if ($page instanceof WP_Post && 'page' === $page->post_type) {
            return;
        }

        wp_insert_post([
            'post_title' => 'post' === content_studio_post_type() ? 'Blog' : ucfirst($slug),
            'post_name' => $slug,
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:shortcode -->[content_studio_blog]<!-- /wp:shortcode -->',
        ]);
    }

    /**
     * Rebuilds the rewrite rules once after an update, and again whenever the
     * post type, its slug or the previous slugs change.
     */
    public static function maybe_flush_rewrite_rules()
    {
        if (get_option('content_studio_rewrite_version') === self::rewrite_version()) {
            return;
        }

        self::ensure_blog_page();
        flush_rewrite_rules();
        update_option('content_studio_rewrite_version', self::rewrite_version());
    }

    /**
     * @return string
     */
    private static function rewrite_version()
    {
        return CONTENT_STUDIO_REWRITE_VERSION . '|' . content_studio_post_type() . '|' . content_studio_blog_slug() . '|' . implode(',', self::previous_slugs()) . '|' . wp_json_encode(self::slug_overrides()) . '|' . implode(',', self::slugs_without_code());
    }

    public function register_shortcode()
    {
        add_shortcode('content_studio_blog', [$this, 'render_blog']);
        add_shortcode('content_studio_language_switcher', [self::class, 'render_language_switcher']);
    }

    /**
     * Standalone switcher. With Polylang or WPML installed their own switcher
     * is the right one, so this steps aside rather than rendering a second.
     */
    public static function render_language_switcher()
    {
        $delegated = Content_Studio_Language::delegated_switcher();

        if (null !== $delegated) {
            return $delegated;
        }

        $available = Content_Studio_Language::available();

        if (count($available) < 2) {
            return '';
        }

        $current = Content_Studio_Language::current();
        $languages = [];

        foreach ($available as $locale) {
            $languages[] = [
                'locale' => $locale,
                'url' => Content_Studio_Language::blog_url($locale),
                'current' => $locale === $current,
            ];
        }

        ob_start();
        content_studio_load_view('language-switcher', ['languages' => $languages]);

        return ob_get_clean();
    }

    public function render_blog()
    {
        $custom_styles = content_studio_get_custom_style_css();
        $style_tag = $custom_styles ? '<style id="content-studio-blog-custom-styles">' . $custom_styles . '</style>' : '';

        $current = self::current_page();
        $posts = Content_Studio_Latest_Posts_Block::get_posts(self::posts_per_page(), $current);

        return $style_tag . Content_Studio_Latest_Posts_Block::render_list(
            [
                'showTitle' => true,
                'title' => Content_Studio_Strings::get('blog_title'),
                'showExcerpt' => true,
                'showMeta' => true,
                'showImage' => true,
            ],
            $posts,
            self::pagination($current, (int) $posts->max_num_pages)
        );
    }

    /**
     * Artikelen per pagina op de blog, uit de instellingen.
     *
     * @return int
     */
    public static function posts_per_page()
    {
        $value = absint(get_option(self::POSTS_PER_PAGE_OPTION, self::DEFAULT_POSTS_PER_PAGE));

        return max(1, min(self::MAX_POSTS_PER_PAGE, $value));
    }

    /**
     * @return int
     */
    public static function current_page()
    {
        return max(1, (int) get_query_var('paged'));
    }

    /**
     * De URL van een pagina van de blog in de taal van dit verzoek.
     *
     * @param int $page
     *
     * @return string
     */
    public static function page_url($page)
    {
        $base = self::base_url();

        if ($page < 2) {
            return $base;
        }

        return untrailingslashit($base) . user_trailingslashit('/page/' . $page, 'paged');
    }

    /**
     * Alles wat views/pagination.php nodig heeft, of null bij één pagina.
     *
     * Pagina's tonen de eerste, de laatste en die rond de huidige; een gat
     * daartussen staat als null in 'pages'.
     *
     * @param int $current
     * @param int $total
     *
     * @return array|null
     */
    public static function pagination($current, $total)
    {
        if ($total < 2) {
            return null;
        }

        $pages = [];
        $previous = 0;

        for ($number = 1; $number <= $total; $number++) {
            if (1 !== $number && $total !== $number && abs($number - $current) > 1) {
                continue;
            }

            if ($previous && $number - $previous > 1) {
                $pages[] = null;
            }

            $pages[] = [
                'number' => $number,
                'url' => self::page_url($number),
                'current' => $number === $current,
            ];

            $previous = $number;
        }

        return [
            'current' => $current,
            'total' => $total,
            'previous_url' => $current > 1 ? self::page_url(min($current, $total) - 1) : '',
            'next_url' => $current < $total ? self::page_url($current + 1) : '',
            'pages' => $pages,
        ];
    }

    /**
     * /nl/blog en /en/blog zijn dezelfde WordPress-pagina; de taal komt uit
     * de URL. Met Polylang of WPML geeft de permalink al de juiste taal.
     *
     * @return string
     */
    private static function base_url()
    {
        if (!Content_Studio_Language::is_standalone()) {
            return get_permalink(get_queried_object_id());
        }

        $locale = (string) get_query_var(Content_Studio_Language::QUERY_VAR);

        if ('' !== $locale && Content_Studio_Language::is_available($locale)) {
            return Content_Studio_Language::blog_url($locale);
        }

        // "All languages" lives on the URL without a code.
        if ('all' === trim((string) get_option(Content_Studio_Language::OPTION, ''))) {
            return get_permalink(get_queried_object_id());
        }

        return Content_Studio_Language::blog_url(Content_Studio_Language::default_locale());
    }
}
