<?php

/**
 * Zet de SEO-velden die de Engine meelevert daadwerkelijk op de pagina.
 *
 * De velden werden al als postmeta opgeslagen maar nergens uitgestuurd, dus
 * een artikel viel terug op de WordPress-titel. De paginatitel gebruikt nu
 * seo_title; de OG- en Twitter-tags gebruiken hun eigen velden en vallen
 * terug op de titel en de meta description.
 *
 * Een SEO-plugin zoals Yoast of Rank Math beheert deze tags zelf. Is er zo'n
 * plugin actief, dan houdt deze klasse zich stil om dubbele tags te
 * voorkomen. Overrulen kan met de filter content_studio_output_meta_tags.
 */
class Content_Studio_Seo
{
    public function __construct()
    {
        add_filter('document_title_parts', [self::class, 'filter_title_parts']);
        add_action('wp_head', [self::class, 'output_meta_tags'], 5);
    }

    /**
     * @param array $parts
     *
     * @return array
     */
    public static function filter_title_parts($parts)
    {
        $seo_title = self::get_field('seo_title');

        if ('' !== $seo_title) {
            $parts['title'] = $seo_title;
        }

        return $parts;
    }

    public static function output_meta_tags()
    {
        if (!self::should_output_meta_tags()) {
            return;
        }

        $title = self::first_filled([self::get_field('og_title'), self::get_field('seo_title')]);
        $description = self::first_filled([self::get_field('og_description'), self::get_field('meta_description')]);
        $twitter_title = self::first_filled([self::get_field('twitter_title'), $title]);
        $twitter_description = self::first_filled([self::get_field('twitter_description'), $description]);

        if ('' === $title && '' === $description) {
            return;
        }

        $tags = [
            'og:title' => $title,
            'og:description' => $description,
            'twitter:title' => $twitter_title,
            'twitter:description' => $twitter_description,
        ];

        foreach ($tags as $property => $value) {
            if ('' === $value) {
                continue;
            }

            $attribute = 0 === strpos($property, 'og:') ? 'property' : 'name';

            printf(
                '<meta %1$s="%2$s" content="%3$s">' . "\n",
                esc_attr($attribute),
                esc_attr($property),
                esc_attr($value)
            );
        }
    }

    /**
     * @return bool
     */
    private static function should_output_meta_tags()
    {
        // Bekende SEO-plugins schrijven deze tags zelf.
        $seo_plugin_active = defined('WPSEO_VERSION')
            || defined('RANK_MATH_VERSION')
            || defined('AIOSEO_VERSION')
            || class_exists('SEOPress');

        return (bool) apply_filters('content_studio_output_meta_tags', !$seo_plugin_active);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private static function get_field($key)
    {
        if (!is_singular('post')) {
            return '';
        }

        $post_id = get_queried_object_id();

        if (!$post_id) {
            return '';
        }

        $value = get_post_meta($post_id, '_content_studio_' . $key, true);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array $values
     *
     * @return string
     */
    private static function first_filled($values)
    {
        foreach ($values as $value) {
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return '';
    }
}
