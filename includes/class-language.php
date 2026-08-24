<?php

/**
 * Single source of truth for "which language is this?".
 *
 * The plugin works on its own, but must not fight a real multilingual plugin
 * when one is installed. Polylang and WPML already filter every query and own
 * the URL structure, so in their presence this class steps back and simply
 * reports what they decided. Everything language-related in the plugin goes
 * through here rather than reading meta or locales directly.
 */
class Content_Studio_Language
{
    const QUERY_VAR = 'cs_lang';

    const OPTION = 'content_studio_article_locale';

    /**
     * @return string 'polylang' | 'wpml' | 'standalone'
     */
    public static function mode()
    {
        if (function_exists('pll_current_language')) {
            return 'polylang';
        }

        if (defined('ICL_SITEPRESS_VERSION') && function_exists('icl_object_id')) {
            return 'wpml';
        }

        return 'standalone';
    }

    public static function is_standalone()
    {
        return 'standalone' === self::mode();
    }

    /**
     * Whether another plugin is already narrowing queries to one language. When
     * it is, adding our own meta filter on top would AND two filters together
     * and empty the blog.
     */
    public static function owns_query_filtering()
    {
        return !self::is_standalone();
    }

    /**
     * 'en_US' and 'en-GB' both become 'en' - the Engine sends bare language
     * codes while WordPress uses full locales.
     */
    public static function normalize($locale)
    {
        $locale = strtolower(trim((string) $locale));

        if ('' === $locale) {
            return '';
        }

        $parts = preg_split('/[-_]/', $locale);

        return $parts[0];
    }

    /**
     * The language the current request should display.
     *
     * Standalone order: the URL segment, then the configured default, then the
     * site language. Returns 'all' only when explicitly configured that way,
     * which disables filtering.
     */
    public static function current()
    {
        $mode = self::mode();

        if ('polylang' === $mode) {
            return self::normalize((string) pll_current_language('slug'));
        }

        if ('wpml' === $mode) {
            return self::normalize((string) apply_filters('wpml_current_language', null));
        }

        $from_url = self::normalize((string) get_query_var(self::QUERY_VAR));

        if ('' !== $from_url && in_array($from_url, self::available(), true)) {
            return apply_filters('content_studio_display_locale', $from_url);
        }

        $configured = trim((string) get_option(self::OPTION, ''));

        if ('all' === $configured) {
            return 'all';
        }

        $locale = '' !== $configured ? self::normalize($configured) : self::primary_locale();

        return apply_filters('content_studio_display_locale', $locale);
    }

    /**
     * The language to show when nothing else decides.
     *
     * Content Studio holds the real answer in the project's primary_locale, so
     * that wins when the API gives it to us. It is not exposed yet, so until
     * then the dominant synced language is a far better guess than the
     * WordPress site locale - a site running in en_US can perfectly well be
     * publishing mostly Dutch articles.
     */
    public static function primary_locale()
    {
        // Deliberately local only. This runs on every front-end request, so it
        // must never reach out to the Engine - the sync refreshes the stored
        // value on its own schedule.
        $engine = self::normalize((string) get_option('content_studio_engine_primary_locale', ''));

        if ('' !== $engine) {
            return $engine;
        }

        $common = self::most_common_locale();

        if ('' !== $common) {
            return $common;
        }

        return self::normalize(get_locale());
    }

    /**
     * Where primary_locale came from, for the settings screen.
     *
     * @return string 'engine' | 'articles' | 'site'
     */
    public static function primary_locale_source()
    {
        if ('' !== self::normalize((string) get_option('content_studio_engine_primary_locale', ''))) {
            return 'engine';
        }

        return '' !== self::most_common_locale() ? 'articles' : 'site';
    }

    public static function most_common_locale()
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value ORDER BY COUNT(*) DESC, meta_value ASC LIMIT 1",
                '_content_studio_locale'
            )
        );

        $cache = self::normalize((string) $value);

        return $cache;
    }

    /**
     * Languages that actually have synced articles. Cached per request because
     * routing and the switcher both ask repeatedly.
     */
    public static function available()
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $mode = self::mode();

        if ('polylang' === $mode && function_exists('pll_languages_list')) {
            $cache = array_values(array_filter(array_map([self::class, 'normalize'], (array) pll_languages_list())));

            return $cache;
        }

        if ('wpml' === $mode) {
            $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            $cache = array_values(array_filter(array_map([self::class, 'normalize'], array_keys((array) $languages))));

            return $cache;
        }

        $stored = (array) get_option('content_studio_engine_locales', []);

        if ([] !== $stored) {
            $cache = array_values(array_filter(array_map([self::class, 'normalize'], $stored)));

            if ([] !== $cache) {
                return $cache;
            }
        }

        global $wpdb;

        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value ASC",
                '_content_studio_locale'
            )
        );

        $locales = [];

        foreach ((array) $values as $value) {
            $normalized = self::normalize($value);

            if ('' !== $normalized) {
                $locales[$normalized] = $normalized;
            }
        }

        $cache = array_values($locales);

        return $cache;
    }

    /**
     * Polylang and WPML ship their own switchers; rendering ours alongside
     * would give the visitor two. Returns null standalone, where the plugin
     * renders its own.
     */
    public static function delegated_switcher()
    {
        $mode = self::mode();

        if ('polylang' === $mode && function_exists('pll_the_languages')) {
            return pll_the_languages(['echo' => 0, 'hide_if_no_translation' => 0]);
        }

        if ('wpml' === $mode) {
            ob_start();
            do_action('wpml_add_language_selector');

            return ob_get_clean();
        }

        return null;
    }

    public static function is_available($locale)
    {
        return in_array(self::normalize($locale), self::available(), true);
    }

    /**
     * The language of one synced article.
     */
    public static function for_post($post_id)
    {
        return self::normalize((string) get_post_meta($post_id, '_content_studio_locale', true));
    }

    /**
     * Blog index for a language. Standalone this is /{lang}/blog; with a
     * multilingual plugin, that plugin rewrites the base URL itself.
     */
    public static function blog_url($locale)
    {
        $locale = self::normalize($locale);
        $mode = self::mode();

        if ('polylang' === $mode) {
            $page = get_page_by_path('blog');

            if ($page && function_exists('pll_get_post')) {
                $translated = pll_get_post($page->ID, $locale);

                if ($translated) {
                    return get_permalink($translated);
                }
            }

            if (function_exists('pll_home_url')) {
                return trailingslashit(pll_home_url($locale)) . 'blog/';
            }

            return home_url('/blog/');
        }

        if ('wpml' === $mode) {
            return apply_filters('wpml_permalink', home_url('/blog/'), $locale);
        }

        return home_url('/' . $locale . '/blog/');
    }

    /**
     * Tells the active multilingual plugin which language a freshly synced post
     * is in. Without this, installing Polylang later would leave every imported
     * article with no language and therefore invisible.
     */
    public static function assign_post_language($post_id, $locale, $cluster_key = '')
    {
        $locale = self::normalize($locale);

        if ('' === $locale) {
            return;
        }

        $mode = self::mode();

        if ('polylang' === $mode && function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $locale);
            self::link_translations_polylang($cluster_key);

            return;
        }

        if ('wpml' === $mode) {
            self::assign_post_language_wpml($post_id, $locale, $cluster_key);
        }

        // Standalone needs nothing: _content_studio_locale is already the
        // record, written by the storage layer.
    }

    /**
     * The posts sharing one cluster_key, keyed by language.
     *
     * cluster_key groups an article with its translations. It is only trusted
     * when every language in the group appears exactly once - if two posts in
     * the same language share a key it is a topic cluster rather than a
     * translation set, and linking them would be wrong.
     *
     * @return array<string,int> language code => post ID, empty when unusable
     */
    public static function translation_group($cluster_key)
    {
        $cluster_key = trim((string) $cluster_key);

        if ('' === $cluster_key) {
            return [];
        }

        $post_ids = get_posts([
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 50,
            'fields' => 'ids',
            'meta_key' => '_content_studio_cluster_key',
            'meta_value' => $cluster_key,
        ]);

        $group = [];

        foreach ($post_ids as $post_id) {
            $locale = self::for_post($post_id);

            if ('' === $locale) {
                continue;
            }

            if (isset($group[$locale])) {
                return []; // same language twice - not a translation set
            }

            $group[$locale] = (int) $post_id;
        }

        return count($group) > 1 ? $group : [];
    }

    private static function link_translations_polylang($cluster_key)
    {
        if (!function_exists('pll_save_post_translations')) {
            return;
        }

        $group = self::translation_group($cluster_key);

        if ([] === $group) {
            return;
        }

        pll_save_post_translations($group);
    }

    /**
     * WPML groups translations by a shared "trid". The first article of a
     * cluster creates one; its siblings must reuse it, or each translation
     * becomes its own standalone post.
     */
    private static function assign_post_language_wpml($post_id, $locale, $cluster_key)
    {
        $trid = null;
        $source_language = null;

        foreach (self::translation_group($cluster_key) as $sibling_locale => $sibling_id) {
            if ((int) $sibling_id === (int) $post_id) {
                continue;
            }

            $sibling_trid = apply_filters('wpml_element_trid', null, $sibling_id, 'post_post');

            if ($sibling_trid) {
                $trid = $sibling_trid;
                $source_language = $sibling_locale;
                break;
            }
        }

        $details = [
            'element_id' => $post_id,
            'element_type' => 'post_post',
            'language_code' => $locale,
        ];

        if ($trid) {
            $details['trid'] = $trid;
            $details['source_language_code'] = $source_language;
        }

        do_action('wpml_set_element_language_details', $details);
    }
}
