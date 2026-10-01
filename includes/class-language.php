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
     * The languages this site publishes. Unset means only the default
     * language (standalone) or every language (Polylang, WPML).
     */
    const PUBLISHED_OPTION = 'content_studio_published_locales';

    /**
     * Whether the default language's URLs carry its code: /nl/blog rather
     * than /blog. Only matters with more than one published language.
     */
    const DEFAULT_CODE_OPTION = 'content_studio_default_locale_in_url';

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

        if ('all' === trim((string) get_option(self::OPTION, ''))) {
            return 'all';
        }

        return apply_filters('content_studio_display_locale', self::default_locale());
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
     * Languages the site serves: Polylang's or WPML's, or standalone the
     * published languages.
     */
    public static function available()
    {
        $mode = self::mode();

        if ('polylang' === $mode && function_exists('pll_languages_list')) {
            return array_values(array_filter(array_map([self::class, 'normalize'], (array) pll_languages_list())));
        }

        if ('wpml' === $mode) {
            $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

            return array_values(array_filter(array_map([self::class, 'normalize'], array_keys((array) $languages))));
        }

        return self::published_locales();
    }

    /**
     * Every language the Engine project has, or failing that the languages
     * among the synced articles: what the site could publish.
     */
    public static function all_locales()
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $stored = array_values(array_filter(array_map([self::class, 'normalize'], (array) get_option('content_studio_engine_locales', []))));

        if ([] !== $stored) {
            $cache = $stored;

            return $cache;
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
     * The languages whose articles go live. Articles in other languages are
     * synced as drafts.
     */
    public static function published_locales()
    {
        $published = self::published_option();

        if (null !== $published) {
            return $published;
        }

        // Polylang and WPML sites have always published every language.
        if (!self::is_standalone()) {
            return self::all_locales();
        }

        $default = self::default_locale();

        return '' !== $default ? [$default] : self::all_locales();
    }

    /**
     * An article without a language is always published: there is nothing
     * to decide on.
     */
    public static function is_published_locale($locale)
    {
        $locale = self::normalize($locale);
        $published = self::published_locales();

        // Without any known language there is nothing to hold back.
        return '' === $locale || [] === $published || in_array($locale, $published, true);
    }

    /**
     * The language /blog shows: the configured one, else the Engine
     * project's primary language, as long as it is published.
     */
    public static function default_locale()
    {
        $configured = trim((string) get_option(self::OPTION, ''));
        $candidates = array_values(array_filter([
            'all' !== $configured ? self::normalize($configured) : '',
            self::primary_locale(),
        ]));

        $published = self::published_option();

        if (null === $published) {
            return $candidates ? $candidates[0] : '';
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $published, true)) {
                return $candidate;
            }
        }

        return $published ? $published[0] : '';
    }

    /**
     * What goes before /{blog slug} for a language: '' or '/nl'. A site that
     * publishes one language has no codes in its URLs; with more, the
     * default language's code can be left out.
     */
    public static function url_prefix($locale)
    {
        $locale = self::normalize($locale);

        if ('' === $locale || count(self::published_locales()) < 2) {
            return '';
        }

        if ($locale === self::default_locale() && !self::default_code_in_url()) {
            return '';
        }

        return '/' . $locale;
    }

    /**
     * @return bool
     */
    public static function default_code_in_url()
    {
        return (bool) get_option(self::DEFAULT_CODE_OPTION, true);
    }

    /**
     * @return array<int, string>|null null when the setting was never saved.
     */
    private static function published_option()
    {
        $option = get_option(self::PUBLISHED_OPTION, null);

        if (!is_array($option)) {
            return null;
        }

        $locales = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $option))));

        return [] !== $locales ? $locales : null;
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
     * Blog index for a language. Standalone this is /{lang}/blog, or /blog
     * when the language has no code in its URLs (see url_prefix()); with a
     * multilingual plugin, that plugin rewrites the base URL itself.
     */
    public static function blog_url($locale)
    {
        $locale = self::normalize($locale);
        $mode = self::mode();

        if ('polylang' === $mode) {
            $page = get_page_by_path(content_studio_blog_slug());

            if ($page && function_exists('pll_get_post')) {
                $translated = pll_get_post($page->ID, $locale);

                if ($translated) {
                    return get_permalink($translated);
                }
            }

            if (function_exists('pll_home_url')) {
                return trailingslashit(pll_home_url($locale)) . user_trailingslashit(content_studio_blog_slug());
            }

            return home_url(user_trailingslashit('/' . content_studio_blog_slug()));
        }

        if ('wpml' === $mode) {
            return apply_filters('wpml_permalink', home_url(user_trailingslashit('/' . content_studio_blog_slug())), $locale);
        }

        return home_url(user_trailingslashit(self::url_prefix($locale) . '/' . content_studio_blog_slug($locale)));
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
            'post_type' => content_studio_post_type(),
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

            $sibling_trid = apply_filters('wpml_element_trid', null, $sibling_id, 'post_' . content_studio_post_type());

            if ($sibling_trid) {
                $trid = $sibling_trid;
                $source_language = $sibling_locale;
                break;
            }
        }

        $details = [
            'element_id' => $post_id,
            'element_type' => 'post_' . content_studio_post_type(),
            'language_code' => $locale,
        ];

        if ($trid) {
            $details['trid'] = $trid;
            $details['source_language_code'] = $source_language;
        }

        do_action('wpml_set_element_language_details', $details);
    }
}
