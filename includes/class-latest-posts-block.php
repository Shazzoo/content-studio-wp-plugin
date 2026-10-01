<?php

class Content_Studio_Latest_Posts_Block
{
    const DEFAULT_ATTRIBUTES = [
        'postsToShow' => 3,
        'showTitle' => true,
        'title' => 'Latest articles',
        'showExcerpt' => true,
        'showMeta' => true,
        'showImage' => true,
    ];

    public function __construct()
    {
        add_action('init', [$this, 'register_block']);
    }

    public function register_block()
    {
        $editor_script_path = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'blocks/latest-posts/editor.js';

        wp_register_script(
            'content-studio-latest-posts-editor',
            plugin_dir_url(CONTENT_STUDIO_PLUGIN_FILE) . 'blocks/latest-posts/editor.js',
            ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render'],
            file_exists($editor_script_path) ? filemtime($editor_script_path) : '1.0',
            true
        );

        register_block_type('content-studio/latest-posts', [
            'api_version' => 2,
            'title' => 'Content Studio Latest Posts',
            'description' => 'Display the latest synced Content Studio posts.',
            'category' => 'widgets',
            'icon' => 'admin-post',
            'editor_script' => 'content-studio-latest-posts-editor',
            'attributes' => [
                'postsToShow' => [
                    'type' => 'number',
                    'default' => 3,
                ],
                'showTitle' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'title' => [
                    'type' => 'string',
                    'default' => 'Latest articles',
                ],
                'showExcerpt' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'showMeta' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'showImage' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
            ],
            'render_callback' => [self::class, 'render'],
        ]);
    }

    public static function render($attributes = [])
    {
        $attributes = wp_parse_args($attributes, self::DEFAULT_ATTRIBUTES);
        $posts = self::get_posts(max(1, min(12, absint($attributes['postsToShow']))));

        return self::render_list($attributes, $posts);
    }

    /**
     * Het overzicht zelf, voor het blok en de blogpagina. Alleen de
     * blogpagina geeft paginering mee.
     *
     * @param array      $attributes
     * @param WP_Query   $posts
     * @param array|null $pagination Zie Content_Studio_Blog_Route::pagination().
     *
     * @return string
     */
    public static function render_list($attributes, $posts, $pagination = null)
    {
        ob_start();

        content_studio_load_view('article-list', [
            'attributes' => wp_parse_args($attributes, self::DEFAULT_ATTRIBUTES),
            'posts' => $posts,
            'pagination' => $pagination,
        ]);

        // Ook als een overschreven view dit vergeet.
        wp_reset_postdata();

        return ob_get_clean();
    }

    public static function render_card($attributes)
    {
        $card = [
            'article_classes' => implode(' ', get_post_class('content-studio-blog__article')),
            'show_image' => !empty($attributes['showImage']),
            'image_html' => has_post_thumbnail() ? get_the_post_thumbnail(null, 'large', ['class' => 'content-studio-blog__image']) : '',
            'image_url' => get_permalink(),
            'image_label' => get_the_title(),
            'show_title' => true,
            'title' => get_the_title(),
            'title_url' => get_permalink(),
            'show_meta' => !empty($attributes['showMeta']),
            'meta_items' => [self::get_api_date(), self::get_read_time()],
            'show_excerpt' => !empty($attributes['showExcerpt']),
            'excerpt' => self::get_excerpt(),
        ];

        content_studio_load_view('article-card', ['card' => $card]);
    }

    /**
     * @param int $posts_per_page
     * @param int $paged
     *
     * @return WP_Query
     */
    public static function get_posts($posts_per_page, $paged = 1)
    {
        return new WP_Query([
            'post_type' => content_studio_post_type(),
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => Content_Studio_Storage::article_meta_query(),
        ]);
    }

    private static function get_excerpt()
    {
        $excerpt = get_post_field('post_excerpt', get_the_ID());

        if ('' !== trim($excerpt)) {
            return $excerpt;
        }

        $content = wp_strip_all_tags(strip_shortcodes(get_post_field('post_content', get_the_ID())));

        return wp_trim_words($content, 25, '');
    }

    private static function get_api_date()
    {
        $date = get_post_meta(get_the_ID(), '_content_studio_published_at', true);

        if ('' === trim((string) $date)) {
            $date = get_post_meta(get_the_ID(), '_content_studio_date', true);
        }

        if ('' === trim((string) $date)) {
            return get_the_date();
        }

        $timestamp = strtotime((string) $date);

        if (!$timestamp) {
            return (string) $date;
        }

        return wp_date(get_option('date_format'), $timestamp);
    }

    private static function get_read_time()
    {
        $content = wp_strip_all_tags(strip_shortcodes(get_post_field('post_content', get_the_ID())));
        $word_count = str_word_count($content);
        $minutes = max(1, (int) ceil($word_count / 200));

        return sprintf(Content_Studio_Strings::get('read_time', Content_Studio_Language::for_post(get_the_ID())), $minutes);
    }
}
