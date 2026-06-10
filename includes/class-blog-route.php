<?php

class Content_Studio_Blog_Route
{
    public function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [self::class, 'maybe_flush_rewrite_rules'], 20);
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
