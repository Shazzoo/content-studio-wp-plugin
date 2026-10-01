<?php

class Content_Studio_Blog_Route
{
    const POSTS_PER_PAGE_OPTION = 'content_studio_articles_per_page';

    const DEFAULT_POSTS_PER_PAGE = 10;

    const MAX_POSTS_PER_PAGE = 48;

    const LEGACY_QUERY_VAR = 'cs_legacy_blog';

    const LEGACY_PATH_QUERY_VAR = 'cs_legacy_path';

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [self::class, 'register_rewrites']);
        add_action('init', [self::class, 'maybe_flush_rewrite_rules'], 20);
        add_filter('query_vars', [self::class, 'register_query_var']);
        add_filter('post_link', [self::class, 'localize_permalink'], 10, 2);
        add_filter('post_type_link', [self::class, 'localize_permalink'], 10, 2);
        // Before redirect_canonical (10), which would first add a slash.
        add_action('template_redirect', [self::class, 'redirect_legacy_blog_url'], 9);
        add_action('template_redirect', [self::class, 'redirect_unlocalized_article']);
        add_action('template_redirect', [self::class, 'not_found_past_last_page']);
    }

    /**
     * /{slug}/page/{n} for the overview page, plus /{lang}/{slug},
     * /{lang}/{slug}/page/{n} and /{lang}/{slug}/{article} on a standalone
     * site. {slug} is content_studio_blog_slug(), so /blog for posts.
     *
     * The page rule is explicit because a custom post type's own rules would
     * read /{slug}/page/2 as an article named "page".
     *
     * The language rules are only registered standalone - Polylang and WPML
     * build their own language URLs and would conflict. The pattern is a
     * generic two-letter code rather than the concrete list of languages, so
     * syncing a new language never requires a rewrite flush. Codes that
     * aren't actually available 404 in redirect_unlocalized_article().
     */
    public static function register_rewrites()
    {
        $slug = content_studio_blog_slug();
        $pattern = preg_quote($slug, '#');

        add_rewrite_rule(
            '^' . $pattern . '/page/?([0-9]{1,})/?$',
            'index.php?pagename=' . $slug . '&paged=$matches[1]',
            'top'
        );

        if (!Content_Studio_Language::is_standalone()) {
            return;
        }

        add_rewrite_rule(
            '^([a-z]{2})/' . $pattern . '/?$',
            'index.php?pagename=' . $slug . '&' . Content_Studio_Language::QUERY_VAR . '=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^([a-z]{2})/' . $pattern . '/page/?([0-9]{1,})/?$',
            'index.php?pagename=' . $slug . '&paged=$matches[2]&' . Content_Studio_Language::QUERY_VAR . '=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^([a-z]{2})/' . $pattern . '/([^/]+)/?$',
            'index.php?post_type=' . content_studio_post_type() . '&name=$matches[2]&' . Content_Studio_Language::QUERY_VAR . '=$matches[1]',
            'top'
        );

        // Before the slug followed the permalink structure, every site used
        // /{lang}/blog. Those URLs are indexed and known to the Engine, so
        // they keep working through a redirect.
        if ('blog' !== $slug) {
            add_rewrite_rule(
                '^([a-z]{2})/blog(/.*)?$',
                'index.php?' . self::LEGACY_QUERY_VAR . '=1&' . self::LEGACY_PATH_QUERY_VAR . '=$matches[2]&' . Content_Studio_Language::QUERY_VAR . '=$matches[1]',
                'top'
            );
        }
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

        return home_url(user_trailingslashit('/' . $locale . '/' . content_studio_blog_slug() . '/' . $post->post_name));
    }

    /**
     * /{lang}/blog/... to the same path under the current slug, e.g.
     * /nl/blog/page/2 to /nl/artikelen/page/2.
     */
    public static function redirect_legacy_blog_url()
    {
        if ('1' !== (string) get_query_var(self::LEGACY_QUERY_VAR) || '' === (string) get_query_var(Content_Studio_Language::QUERY_VAR)) {
            return;
        }

        $locale = Content_Studio_Language::normalize((string) get_query_var(Content_Studio_Language::QUERY_VAR));

        // An unknown language 404s in redirect_unlocalized_article().
        if (!Content_Studio_Language::is_available($locale)) {
            return;
        }

        $path = '/' . trim((string) get_query_var(self::LEGACY_PATH_QUERY_VAR), '/');

        wp_safe_redirect(home_url(user_trailingslashit(untrailingslashit('/' . $locale . '/' . content_studio_blog_slug() . $path))), 301);
        exit;
    }

    /**
     * Sends the old /{slug}/{article} URLs to their localized equivalent, and 404s a
     * language prefix that has no articles.
     */
    public static function redirect_unlocalized_article()
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

            return;
        }

        if ('' !== $requested || !is_singular(content_studio_post_type())) {
            return;
        }

        $post_id = get_queried_object_id();
        $locale = Content_Studio_Language::for_post($post_id);

        if ('' === $locale) {
            return;
        }

        $target = get_permalink($post_id);

        if ($target && $target !== home_url(add_query_arg([]))) {
            wp_safe_redirect($target, 301);
            exit;
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
     * post type or its slug changes.
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
        return CONTENT_STUDIO_REWRITE_VERSION . '|' . content_studio_post_type() . '|' . content_studio_blog_slug();
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
        $locale = (string) get_query_var(Content_Studio_Language::QUERY_VAR);

        if (Content_Studio_Language::is_standalone() && '' !== $locale && Content_Studio_Language::is_available($locale)) {
            return Content_Studio_Language::blog_url($locale);
        }

        return get_permalink(get_queried_object_id());
    }
}
