<?php

class Content_Studio_Latest_Posts_Block
{
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
        $attributes = wp_parse_args($attributes, [
            'postsToShow' => 3,
            'showTitle' => true,
            'title' => 'Latest articles',
            'showExcerpt' => true,
            'showMeta' => true,
            'showImage' => true,
        ]);

        $posts = self::get_posts(absint($attributes['postsToShow']));
        ob_start();
?>
        <div class="content-studio-blog content-studio-latest-posts">
            <?php if (!empty($attributes['showTitle']) && '' !== trim((string) $attributes['title'])) : ?>
                <header class="content-studio-blog__header">
                    <h2 class="content-studio-blog__title"><?php echo esc_html($attributes['title']); ?></h2>
                </header>
            <?php endif; ?>

            <?php if ($posts->have_posts()) : ?>
                <div class="content-studio-blog__grid">
                    <?php while ($posts->have_posts()) : $posts->the_post(); ?>
                        <?php self::render_card($attributes); ?>
                    <?php endwhile; ?>
                </div>
                <?php wp_reset_postdata(); ?>
            <?php else : ?>
                <p>No articles yet...</p>
            <?php endif; ?>
        </div>
<?php
        return ob_get_clean();
    }

    private static function render_card($attributes)
    {
?>
        <article <?php post_class('content-studio-blog__article'); ?>>
            <?php if (!empty($attributes['showImage'])) : ?>
                <?php if (has_post_thumbnail()) : ?>
                    <a class="content-studio-blog__image-link" href="<?php the_permalink(); ?>">
                        <?php the_post_thumbnail('large', ['class' => 'content-studio-blog__image']); ?>
                    </a>
                <?php else : ?>
                    <a class="content-studio-blog__image-link content-studio-blog__image-placeholder" href="<?php the_permalink(); ?>" aria-label="<?php the_title_attribute(); ?>">
                        <span>Content Studio</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <h2 class="content-studio-blog__article-title">
                <a class="content-studio-blog__article-link" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
            </h2>

            <?php if (!empty($attributes['showMeta'])) : ?>
                <div class="content-studio-blog__meta">
                    <span><?php echo esc_html(self::get_api_date()); ?></span>
                    <span><?php echo esc_html(self::get_read_time()); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($attributes['showExcerpt'])) : ?>
                <div class="content-studio-blog__excerpt">
                    <p><?php echo esc_html(self::get_excerpt()); ?></p>
                </div>
            <?php endif; ?>
        </article>
<?php
    }

    private static function get_posts($posts_to_show)
    {
        return new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(12, $posts_to_show)),
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_content_studio_external_id',
                    'compare' => 'EXISTS',
                ],
            ],
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

        return sprintf('%d min read', $minutes);
    }
}
