<?php

class Content_Studio_Blog_Route
{
    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [self::class, 'register_language_rewrites']);
        add_action('init', [self::class, 'maybe_flush_rewrite_rules'], 20);
        add_filter('query_vars', [self::class, 'register_query_var']);
        add_filter('post_link', [self::class, 'localize_permalink'], 10, 2);
        add_action('template_redirect', [self::class, 'redirect_unlocalized_article']);
    }

    /**
     * /{lang}/blog and /{lang}/blog/{slug}. Only registered standalone -
     * Polylang and WPML build their own language URLs and would conflict.
     *
     * The pattern is a generic two-letter code rather than the concrete list of
     * languages, so syncing a new language never requires a rewrite flush.
     * Codes that aren't actually available 404 in redirect_unlocalized_article().
     */
    public static function register_language_rewrites()
    {
        if (!Content_Studio_Language::is_standalone()) {
            return;
        }

        add_rewrite_rule(
            '^([a-z]{2})/blog/?$',
            'index.php?pagename=blog&' . Content_Studio_Language::QUERY_VAR . '=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^([a-z]{2})/blog/([^/]+)/?$',
            'index.php?name=$matches[2]&' . Content_Studio_Language::QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    public static function register_query_var($vars)
    {
        $vars[] = Content_Studio_Language::QUERY_VAR;

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

        if (!$post instanceof WP_Post || 'post' !== $post->post_type) {
            return $permalink;
        }

        $locale = Content_Studio_Language::for_post($post->ID);

        if ('' === $locale) {
            return $permalink;
        }

        return home_url('/' . $locale . '/blog/' . $post->post_name . '/');
    }

    /**
     * Sends the old /blog/{slug} URLs to their localized equivalent, and 404s a
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

        if ('' !== $requested || !is_singular('post')) {
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

    public static function activate()
    {
        self::ensure_blog_page();
        flush_rewrite_rules();
        update_option('content_studio_rewrite_version', CONTENT_STUDIO_REWRITE_VERSION);
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }

    public static function ensure_blog_page()
    {
        $page = get_page_by_path('blog');

        if ($page instanceof WP_Post && 'page' === $page->post_type) {
            return;
        }

        wp_insert_post([
            'post_title' => 'Blog',
            'post_name' => 'blog',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:shortcode -->[content_studio_blog]<!-- /wp:shortcode -->',
        ]);
    }

    public static function maybe_flush_rewrite_rules()
    {
        if (get_option('content_studio_rewrite_version') === CONTENT_STUDIO_REWRITE_VERSION) {
            return;
        }

        self::ensure_blog_page();
        flush_rewrite_rules();
        update_option('content_studio_rewrite_version', CONTENT_STUDIO_REWRITE_VERSION);
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
        $items = '';

        foreach ($available as $locale) {
            $items .= sprintf(
                '<li class="content-studio-language-switcher__item"><a class="content-studio-language-switcher__link%s" href="%s"%s>%s</a></li>',
                $locale === $current ? ' is-current' : '',
                esc_url(Content_Studio_Language::blog_url($locale)),
                $locale === $current ? ' aria-current="true"' : '',
                esc_html(strtoupper($locale))
            );
        }

        return '<nav class="content-studio-language-switcher" aria-label="Language"><ul class="content-studio-language-switcher__list">' . $items . '</ul></nav>';
    }

    public function render_blog()
    {
        $custom_styles = content_studio_get_custom_style_css();
        $style_tag = $custom_styles ? '<style id="content-studio-blog-custom-styles">' . $custom_styles . '</style>' : '';

        return $style_tag . Content_Studio_Latest_Posts_Block::render([
            'postsToShow' => 10,
            'showTitle' => true,
            'title' => 'Artikelen',
            'showExcerpt' => true,
            'showMeta' => true,
            'showImage' => true,
        ]);
    }
}
