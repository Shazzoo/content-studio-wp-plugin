<?php

/**
 * Toont onder een hub-artikel de artikelen die bij die hub horen: de
 * artikelen waarvan hub_content_id naar de Engine-ID van deze post wijst.
 *
 * WordPress rendert artikelen met het single-template van het thema, dus de
 * sectie komt via the_content achter de tekst. Een artikel zonder
 * onderliggende artikelen krijgt niets extra.
 */
class Content_Studio_Hub_Articles
{
    /**
     * De teksten volgen de taal van het artikel, niet die van de site.
     */
    const STRINGS = [
        'en' => [
            'title' => 'Cluster overview',
            'intro' => 'Dive into the details with our specialized articles on this topic.',
        ],
        'nl' => [
            'title' => 'Cluster overzicht',
            'intro' => 'Duik in de details met onze gespecialiseerde artikelen over dit onderwerp.',
        ],
    ];

    public function __construct()
    {
        add_filter('the_content', [self::class, 'append_hub_articles']);
    }

    public static function append_hub_articles($content)
    {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();

        if ($post_id !== get_queried_object_id()) {
            return $content;
        }

        $external_id = (string) get_post_meta($post_id, '_content_studio_external_id', true);

        if ('' === $external_id) {
            return $content;
        }

        $locale = Content_Studio_Language::for_post($post_id);
        $articles = self::get_hub_articles($external_id, $locale);

        if (!$articles->have_posts()) {
            return $content;
        }

        $strings = isset(self::STRINGS[$locale]) ? self::STRINGS[$locale] : self::STRINGS['en'];
        $attributes = [
            'showExcerpt' => true,
            'showMeta' => true,
            'showImage' => true,
        ];

        ob_start();
?>
        <section class="content-studio-blog content-studio-hub-articles">
            <header class="content-studio-blog__header">
                <h2 class="content-studio-blog__title"><?php echo esc_html($strings['title']); ?></h2>
                <p class="content-studio-hub-articles__intro"><?php echo esc_html($strings['intro']); ?></p>
            </header>

            <div class="content-studio-blog__grid">
                <?php while ($articles->have_posts()) : $articles->the_post(); ?>
                    <?php Content_Studio_Latest_Posts_Block::render_card($attributes); ?>
                <?php endwhile; ?>
            </div>
        </section>
<?php
        wp_reset_postdata();

        return $content . ob_get_clean();
    }

    /**
     * @param string $external_id
     * @param string $locale
     *
     * @return WP_Query
     */
    private static function get_hub_articles($external_id, $locale)
    {
        $meta_query = [
            [
                'key' => '_content_studio_hub_content_id',
                'value' => $external_id,
            ],
        ];

        // Alleen artikelen in de taal van de hub, net als in de Laravel-plugin.
        if ('' !== $locale) {
            $meta_query[] = [
                'key' => '_content_studio_locale',
                'value' => '^' . $locale . '([-_]|$)',
                'compare' => 'REGEXP',
            ];
        }

        return new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $meta_query,
        ]);
    }
}
