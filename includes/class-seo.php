<?php

/**
 * Zet de SEO-velden die de Engine meelevert daadwerkelijk op de pagina.
 *
 * De paginatitel gebruikt seo_title; de meta description, OG- en
 * Twitter-tags gebruiken hun eigen velden en vallen terug op de titel en de
 * meta description. Daarnaast krijgen artikelen en de blogpagina JSON-LD.
 *
 * Een SEO-plugin zoals Yoast of Rank Math beheert deze tags zelf. Is er zo'n
 * plugin actief, dan houdt deze klasse zich stil om dubbele tags te
 * voorkomen. Overrulen kan met de filter content_studio_output_meta_tags.
 * Yoast krijgt de velden van de Engine via zijn eigen filters, maar een
 * waarde die een redacteur in Yoast invult gaat altijd voor.
 */
class Content_Studio_Seo
{
    public function __construct()
    {
        add_filter('document_title_parts', [self::class, 'filter_title_parts']);
        add_filter('get_canonical_url', [self::class, 'filter_canonical_url']);
        add_action('wp_head', [self::class, 'output_meta_tags'], 5);
        add_action('wp_head', [self::class, 'output_json_ld'], 6);

        add_filter('wpseo_replacements', [self::class, 'filter_yoast_replacements'], 10, 2);
        add_filter('wpseo_frontend_presentation', [self::class, 'filter_yoast_presentation']);
        add_filter('wpseo_metadesc', [self::class, 'filter_yoast_metadesc']);
        add_filter('wpseo_opengraph_title', [self::class, 'filter_yoast_opengraph_title']);
        add_filter('wpseo_opengraph_desc', [self::class, 'filter_yoast_opengraph_desc']);
        add_filter('wpseo_twitter_title', [self::class, 'filter_yoast_twitter_title']);
        add_filter('wpseo_twitter_description', [self::class, 'filter_yoast_twitter_description']);
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

    /**
     * /nl/blog en /en/blog renderen dezelfde WordPress-pagina, waarvan de
     * canonical /blog is, en /blog/page/2 heeft standaard dezelfde canonical
     * als pagina 1. Zonder deze filter voegen zoekmachines de talen samen en
     * vinden ze de oudere artikelen niet via de blog.
     *
     * @param string $canonical_url
     *
     * @return string
     */
    public static function filter_canonical_url($canonical_url)
    {
        $blog_url = self::blog_page_url();

        return '' !== $blog_url ? $blog_url : $canonical_url;
    }

    /**
     * Hetzelfde voor Yoast. Die leidt canonical, og:url en de schema-URL af
     * van de permalink van de presentatie, dus die ene waarde volstaat. Een
     * canonical die een redacteur in Yoast heeft ingevuld blijft staan.
     *
     * @param object $presentation
     *
     * @return object
     */
    public static function filter_yoast_presentation($presentation)
    {
        $blog_url = self::blog_page_url();

        if ('' === $blog_url) {
            return $presentation;
        }

        $presentation->permalink = $blog_url;

        if (empty($presentation->model->canonical)) {
            $presentation->canonical = $blog_url;
        }

        return $presentation;
    }

    public static function output_meta_tags()
    {
        if (!self::should_output_meta_tags()) {
            return;
        }

        $post_id = self::article_id();

        if (!$post_id) {
            return;
        }

        $title = self::first_filled([self::get_field('og_title'), self::get_field('seo_title'), get_the_title($post_id)]);
        $description = self::first_filled([self::get_field('og_description'), self::get_field('meta_description')]);
        $image = self::image($post_id);

        $tags = [
            'description' => self::get_field('meta_description'),
            'og:type' => 'article',
            'og:url' => get_permalink($post_id),
            'og:title' => $title,
            'og:description' => $description,
            'og:image' => $image ? $image['url'] : '',
            'og:image:alt' => $image ? $image['alt'] : '',
            'article:published_time' => get_post_time('c', true, $post_id),
            'article:modified_time' => get_post_modified_time('c', true, $post_id),
            'twitter:card' => $image ? 'summary_large_image' : 'summary',
            'twitter:title' => self::first_filled([self::get_field('twitter_title'), $title]),
            'twitter:description' => self::first_filled([self::get_field('twitter_description'), $description]),
            'twitter:image' => $image ? $image['url'] : '',
        ];

        foreach ($tags as $property => $value) {
            if ('' === (string) $value) {
                continue;
            }

            $attribute = preg_match('/^(og|article):/', $property) ? 'property' : 'name';

            printf(
                '<meta %1$s="%2$s" content="%3$s">' . "\n",
                esc_attr($attribute),
                esc_attr($property),
                esc_attr($value)
            );
        }
    }

    /**
     * BlogPosting op een artikel, Blog met een ItemList op de blogpagina.
     * SEO-plugins zetten hun eigen schema neer, dus dit volgt dezelfde
     * schakelaar als de meta tags.
     */
    public static function output_json_ld()
    {
        if (!self::should_output_meta_tags()) {
            return;
        }

        $post_id = self::article_id();

        if ($post_id) {
            $data = self::article_json_ld($post_id);
        } elseif (Content_Studio_Blog_Route::is_blog_page()) {
            $data = self::blog_json_ld();
        } else {
            return;
        }

        // JSON_HEX_TAG, zodat een '</script>' in een titel het blok niet
        // kan afsluiten.
        echo '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>' . "\n";
    }

    /**
     * %%title%% in Yoasts titeltemplates wordt de seo_title van de Engine.
     * Zo blijft Yoasts opbouw met scheidingsteken en sitenaam staan, en
     * gebruikt een eigen Yoast-titel zonder %%title%% gewoon zijn eigen tekst.
     *
     * Op de blogpagina vult dit %%page%%: voor Yoast is dat één pagina, dus
     * zonder dit krijgen alle pagina's van de blog dezelfde titel.
     *
     * @param array        $replacements
     * @param object|array $args
     *
     * @return array
     */
    public static function filter_yoast_replacements($replacements, $args)
    {
        // Yoast laat %%page%% weg als het zelf niets in te vullen heeft, dus
        // niet op array_key_exists controleren. Zonder %%page%% in het
        // template doet de waarde niets.
        if (Content_Studio_Blog_Route::is_blog_page() && Content_Studio_Blog_Route::current_page() > 1) {
            $replacements['%%page%%'] = self::yoast_blog_page_label();
        }

        $post_id = self::article_id();
        $args_id = is_object($args) && isset($args->ID) ? (int) $args->ID : (is_array($args) && isset($args['ID']) ? (int) $args['ID'] : 0);

        if (!$post_id || $args_id !== $post_id || !array_key_exists('%%title%%', $replacements)) {
            return $replacements;
        }

        $seo_title = self::get_field('seo_title');

        if ('' !== $seo_title) {
            $replacements['%%title%%'] = $seo_title;
        }

        return $replacements;
    }

    /**
     * Zoals Yoast %%page%% zelf invult: "- Page 2 of 10", leeg op pagina 1.
     *
     * @return string
     */
    private static function yoast_blog_page_label()
    {
        $current = Content_Studio_Blog_Route::current_page();

        if ($current < 2) {
            return '';
        }

        $total = (int) Content_Studio_Latest_Posts_Block::get_posts(Content_Studio_Blog_Route::posts_per_page(), $current)->max_num_pages;
        $sep = function_exists('YoastSEO') ? YoastSEO()->helpers->options->get_title_separator() : '-';

        return sprintf($sep . ' ' . __('Page %1$d of %2$d', 'wordpress-seo'), $current, max($current, $total));
    }

    public static function filter_yoast_metadesc($value)
    {
        return self::yoast_value($value, '_yoast_wpseo_metadesc', ['meta_description']);
    }

    public static function filter_yoast_opengraph_title($value)
    {
        return self::yoast_value($value, '_yoast_wpseo_opengraph-title', ['og_title']);
    }

    public static function filter_yoast_opengraph_desc($value)
    {
        return self::yoast_value($value, '_yoast_wpseo_opengraph-description', ['og_description', 'meta_description']);
    }

    public static function filter_yoast_twitter_title($value)
    {
        return self::yoast_value($value, '_yoast_wpseo_twitter-title', ['twitter_title']);
    }

    public static function filter_yoast_twitter_description($value)
    {
        return self::yoast_value($value, '_yoast_wpseo_twitter-description', ['twitter_description']);
    }

    /**
     * Het Engine-veld in plaats van Yoasts automatische waarde, behalve als
     * een redacteur het veld in Yoast zelf heeft ingevuld.
     *
     * @param mixed  $value
     * @param string $yoast_meta_key
     * @param array  $fields
     *
     * @return mixed
     */
    private static function yoast_value($value, $yoast_meta_key, $fields)
    {
        $post_id = self::article_id();

        if (!$post_id || '' !== trim((string) get_post_meta($post_id, $yoast_meta_key, true))) {
            return $value;
        }

        $engine_value = self::first_filled(array_map([self::class, 'get_field'], $fields));

        return '' !== $engine_value ? $engine_value : $value;
    }

    /**
     * @param int $post_id
     *
     * @return array
     */
    private static function article_json_ld($post_id)
    {
        $url = get_permalink($post_id);

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => get_the_title($post_id),
            'description' => self::get_field('meta_description'),
            'url' => $url,
            'datePublished' => get_post_time('c', true, $post_id),
            'dateModified' => get_post_modified_time('c', true, $post_id),
            'author' => self::authors($post_id),
            'publisher' => self::publisher(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $url,
            ],
        ];

        $image = self::image($post_id);

        if ($image) {
            $data['image'] = [
                '@type' => 'ImageObject',
                'url' => $image['url'],
            ];
        }

        $locale = Content_Studio_Language::for_post($post_id);

        if ('' !== $locale) {
            $data['inLanguage'] = $locale;
        }

        return $data;
    }

    /**
     * @return array
     */
    private static function blog_json_ld()
    {
        $url = wp_get_canonical_url(get_queried_object_id()) ?: get_permalink(get_queried_object_id());

        // Dezelfde query als de blogpagina, zodat de lijst de artikelen op
        // deze pagina noemt.
        $articles = wp_list_pluck(
            Content_Studio_Latest_Posts_Block::get_posts(
                Content_Studio_Blog_Route::posts_per_page(),
                Content_Studio_Blog_Route::current_page()
            )->posts,
            'ID'
        );

        $items = [];

        foreach ($articles as $index => $article_id) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => get_permalink($article_id),
                'name' => get_the_title($article_id),
            ];
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            'name' => get_the_title(get_queried_object_id()),
            'url' => $url,
            'publisher' => self::publisher(),
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListElement' => $items,
            ],
        ];

        $description = get_bloginfo('description');

        if ('' !== trim($description)) {
            $data['description'] = $description;
        }

        return $data;
    }

    /**
     * De auteur(s) van de Engine, die er meerdere gescheiden door komma's of
     * puntkomma's kan sturen; anders de WordPress-auteur van de post.
     *
     * @param int $post_id
     *
     * @return array
     */
    private static function authors($post_id)
    {
        $names = preg_split('/\s*[,;]\s*/', self::get_field('author_name'), -1, PREG_SPLIT_NO_EMPTY);

        if (!$names) {
            $names = [get_the_author_meta('display_name', (int) get_post_field('post_author', $post_id))];
        }

        $authors = [];

        foreach (array_filter($names) as $name) {
            $authors[] = [
                '@type' => 'Person',
                'name' => $name,
            ];
        }

        return $authors;
    }

    /**
     * @return array
     */
    private static function publisher()
    {
        $publisher = [
            '@type' => 'Organization',
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
        ];

        $logo = get_site_icon_url(512);

        if ($logo) {
            $publisher['logo'] = [
                '@type' => 'ImageObject',
                'url' => $logo,
            ];
        }

        return $publisher;
    }

    /**
     * @param int $post_id
     *
     * @return array|null url en alt van de uitgelichte afbeelding
     */
    private static function image($post_id)
    {
        $attachment_id = get_post_thumbnail_id($post_id);
        $url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'full') : '';

        if (!$url) {
            return null;
        }

        return [
            'url' => $url,
            'alt' => self::first_filled([
                self::get_field('featured_image_alt'),
                (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            ]),
        ];
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
     * De post-ID als dit verzoek een gesynchroniseerd artikel toont, anders 0.
     *
     * @return int
     */
    private static function article_id()
    {
        if (!is_singular(content_studio_post_type())) {
            return 0;
        }

        $post_id = get_queried_object_id();

        return $post_id && '' !== (string) get_post_meta($post_id, '_content_studio_external_id', true) ? $post_id : 0;
    }

    /**
     * De URL van de huidige pagina van de blog, in de taal uit de URL, of ''
     * als dit verzoek niet de blogpagina is.
     *
     * @return string
     */
    private static function blog_page_url()
    {
        if (!Content_Studio_Blog_Route::is_blog_page()) {
            return '';
        }

        return Content_Studio_Blog_Route::page_url(Content_Studio_Blog_Route::current_page());
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private static function get_field($key)
    {
        $post_id = self::article_id();

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
