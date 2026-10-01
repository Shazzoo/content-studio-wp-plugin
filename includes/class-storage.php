<?php

class Content_Studio_Storage
{
    /**
     * Only content the editors signed off on belongs on the site. 'published'
     * is part of that set: the Engine moves content to it as soon as we
     * confirm publication, so leaving it out would make every confirmed
     * article fall out of sync again on the next run.
     */
    const SYNCABLE_STATUSES = ['approved', 'published'];

    /**
     * Set on an article the plugin made a draft because the site does not
     * publish its language, so publishing that language again only touches
     * these and never an editor's own drafts.
     */
    const UNPUBLISHED_LANGUAGE_META = '_content_studio_unpublished_language';

    /**
     * The published languages that apply_published_locales() last applied.
     */
    const APPLIED_LOCALES_OPTION = 'content_studio_applied_published_locales';

    public static function save($content)
    {
        self::maybe_store_primary_locale($content);

        $articles = self::extract_articles($content);
        $saved = 0;

        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }

            $external_id = self::get_article_external_id($article);
            $title = isset($article['title']) ? sanitize_text_field($article['title']) : '';

            if (empty($external_id) || empty($title)) {
                continue;
            }

            if (self::save_article($external_id, $article)) {
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Meta query matching synced articles, narrowed to one language unless the
     * site is configured to show every language.
     */
    public static function article_meta_query()
    {
        $meta_query = [
            [
                'key' => '_content_studio_external_id',
                'compare' => 'EXISTS',
            ],
        ];

        // Polylang and WPML already narrow every query to one language; adding
        // our filter on top would AND the two together and empty the blog.
        if (Content_Studio_Language::owns_query_filtering()) {
            return $meta_query;
        }

        $locale = self::get_display_locale();

        if ('all' !== $locale && '' !== $locale) {
            // Anchored so 'nl' matches 'nl', 'nl-NL' and 'nl_NL', but never
            // an unrelated locale that merely starts with the same letters.
            $meta_query[] = [
                'key' => '_content_studio_locale',
                'value' => '^' . $locale . '([-_]|$)',
                'compare' => 'REGEXP',
            ];
        }

        return $meta_query;
    }

    /**
     * Which language the front end should show. Empty setting means "follow
     * the site language"; 'all' disables filtering entirely.
     */
    public static function get_display_locale()
    {
        return Content_Studio_Language::current();
    }

    /**
     * 'en_US' and 'en-GB' both become 'en' - the Engine sends bare language
     * codes, while WordPress uses full locales.
     */
    public static function normalize_locale($locale)
    {
        return Content_Studio_Language::normalize($locale);
    }

    /**
     * The languages actually present in the synced articles, for the settings
     * dropdown.
     */
    public static function get_available_locales()
    {
        return Content_Studio_Language::available();
    }


    /**
     * Brings the synced articles in line with the published languages, once
     * per change of that list: articles in a language the site no longer
     * publishes become drafts, and the drafts the plugin made for a language
     * that is published again go back live. Confirmed articles do not come
     * back in the sync, so this cannot wait for it.
     */
    public static function maybe_apply_published_locales()
    {
        $published = Content_Studio_Language::published_locales();
        sort($published);
        $key = implode(',', $published);

        if (get_option(self::APPLIED_LOCALES_OPTION, null) === $key) {
            return;
        }

        // First, so a slow run is not started again by the next request.
        update_option(self::APPLIED_LOCALES_OPTION, $key);

        $post_ids = get_posts([
            'post_type' => content_studio_post_type(),
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_content_studio_external_id',
        ]);

        foreach ($post_ids as $post_id) {
            $hidden = !Content_Studio_Language::is_published_locale(Content_Studio_Language::for_post($post_id));
            $status = get_post_status($post_id);

            if ($hidden && 'publish' === $status) {
                update_post_meta($post_id, self::UNPUBLISHED_LANGUAGE_META, 1);
                wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            } elseif (!$hidden && 'draft' === $status && get_post_meta($post_id, self::UNPUBLISHED_LANGUAGE_META, true)) {
                delete_post_meta($post_id, self::UNPUBLISHED_LANGUAGE_META);
                wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            }
        }
    }

    public static function count_posts()
    {
        $query = new WP_Query([
            'post_type' => content_studio_post_type(),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => [
                [
                    'key' => '_content_studio_external_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }

    public static function get_fallback_author_id()
    {
        $user_id = absint(get_option('content_studio_fallback_author_id', 0));

        if ($user_id && get_user_by('id', $user_id)) {
            return $user_id;
        }

        $legacy_author_name = trim((string) get_option('content_studio_fallback_author_name', ''));

        if ('' !== $legacy_author_name) {
            $legacy_user_id = self::get_author_id_by_name($legacy_author_name, false);

            if ($legacy_user_id) {
                update_option('content_studio_fallback_author_id', $legacy_user_id);
                return $legacy_user_id;
            }
        }

        $content_studio_user_id = self::get_content_studio_user_id();
        update_option('content_studio_fallback_author_id', $content_studio_user_id);

        return $content_studio_user_id;
    }

    public static function get_content_studio_user_id()
    {
        return self::get_author_id_by_name('Content Studio', true);
    }

    /**
     * The Engine knows each project's primary_locale but does not expose it on
     * the contents endpoint yet. This picks it up automatically if that ever
     * changes, from either the payload root or its meta block.
     */
    private static function maybe_store_primary_locale($content)
    {
        $project = Content_Studio_API_Client::fetch_project(true);

        if (is_wp_error($project)) {
            return;
        }

        $primary = Content_Studio_Language::normalize((string) ($project['primary_locale'] ?? ''));

        if ('' !== $primary && get_option('content_studio_engine_primary_locale', '') !== $primary) {
            update_option('content_studio_engine_primary_locale', $primary);
        }

        $locales = array_values(array_filter(array_map(
            [Content_Studio_Language::class, 'normalize'],
            (array) ($project['locales'] ?? [])
        )));

        if ([] !== $locales) {
            update_option('content_studio_engine_locales', $locales);
        }
    }

    private static function extract_articles($content)
    {
        if (!is_array($content)) {
            return [];
        }

        if (isset($content['articles']) && is_array($content['articles'])) {
            return $content['articles'];
        }

        if (isset($content['data']) && is_array($content['data'])) {
            return $content['data'];
        }

        return array_values($content) === $content ? $content : [];
    }

    private static function save_article($external_id, $article)
    {
        $image_url = self::get_article_image_url($article);
        $existing = self::get_by_external_id($external_id);

        // Unapproved content is never imported. An article that is already
        // here does get updated, so withdrawing approval in the Engine sends
        // the post back to draft rather than leaving it live.
        if (!$existing && !self::is_syncable($article)) {
            return false;
        }

        $post_date = self::format_datetime($article['published_at'] ?? $article['date'] ?? null);
        $content = $article['body_html'] ?? $article['content'] ?? '';
        $excerpt = $article['excerpt'] ?? $article['meta_description'] ?? '';
        $author_id = self::get_author_id($article);
        // Een eigen post type zonder categorieën krijgt er ook geen.
        $category_id = is_object_in_taxonomy(content_studio_post_type(), 'category') ? self::get_category_id() : 0;

        $language_hidden = !Content_Studio_Language::is_published_locale($article['locale'] ?? '');

        $post_data = [
            'post_title' => sanitize_text_field($article['title']),
            'post_content' => wp_kses_post($content),
            'post_excerpt' => sanitize_textarea_field($excerpt),
            'post_status' => $language_hidden ? 'draft' : self::get_post_status($article),
            'post_type' => content_studio_post_type(),
        ];

        if ($author_id) {
            $post_data['post_author'] = $author_id;
        }

        if ($category_id) {
            $post_data['post_category'] = [$category_id];
        }

        if (!empty($article['slug'])) {
            $post_data['post_name'] = sanitize_title($article['slug']);
        }

        if ($post_date) {
            $post_data['post_date_gmt'] = $post_date;
            $post_data['post_date'] = get_date_from_gmt($post_date);
        }

        if ($existing) {
            $post_data['ID'] = $existing->ID;
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($post_id)) {
            return false;
        }

        if (is_string($content)) {
            $content = self::replace_image_placeholders(
                $content,
                $article,
                static function ($image_url, $placeholder_id, $entry) use ($article, $post_id) {
                    $type = isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : 'image';

                    return self::maybe_import_placeholder_image($image_url, $article, $post_id, $placeholder_id, $type);
                }
            );

            $content_update = wp_update_post([
                'ID' => $post_id,
                'post_content' => wp_kses_post($content),
            ], true);

            if (is_wp_error($content_update)) {
                return false;
            }
        }

        if ($language_hidden) {
            update_post_meta($post_id, self::UNPUBLISHED_LANGUAGE_META, 1);
        } else {
            delete_post_meta($post_id, self::UNPUBLISHED_LANGUAGE_META);
        }

        update_post_meta($post_id, '_content_studio_external_id', $external_id);
        update_post_meta($post_id, '_content_studio_image_url', esc_url_raw($image_url));
        update_post_meta($post_id, '_content_studio_published_at', sanitize_text_field((string) ($article['published_at'] ?? '')));
        update_post_meta($post_id, '_content_studio_date', sanitize_text_field((string) ($article['date'] ?? '')));
        update_post_meta($post_id, '_content_studio_raw_payload', wp_json_encode($article));
        self::save_article_meta($post_id, $article);

        Content_Studio_Language::assign_post_language($post_id, $article['locale'] ?? '', $article['cluster_key'] ?? '');

        self::maybe_import_featured_image($post_id, $image_url, $article);

        // The external ID is only available now, so a post created in this
        // request could not be confirmed from transition_post_status yet.
        Content_Studio_Publish_Confirmation::maybe_confirm($post_id);

        return true;
    }

    private static function get_post_status($article)
    {
        if (!isset($article['status'])) {
            return 'publish';
        }

        return self::is_syncable($article) ? 'publish' : 'draft';
    }

    private static function is_syncable($article)
    {
        $status = isset($article['status']) ? strtolower(trim((string) $article['status'])) : '';

        return in_array($status, self::SYNCABLE_STATUSES, true);
    }

    /**
     * Vervangt de afbeeldingsplaceholders die de Engine in de tekst zet.
     *
     * De Engine levert alle typen (diagram, illustratie, en later grafieken)
     * in één image_placeholders-lijst waarin elk item een type heeft. De oude
     * losse lijsten blijven werken zolang die lijst leeg of afwezig is.
     */
    private static function replace_image_placeholders($content, $article, $image_url_resolver = null)
    {
        $entries = self::collect_placeholder_entries($article);

        if ([] === $entries) {
            return $content;
        }

        $replacements = [];

        foreach ($entries as $id => $entry) {
            $replacement = self::render_placeholder($entry, $image_url_resolver, $id);

            if (null !== $replacement) {
                $replacements[$id] = $replacement;
            }
        }

        if ([] === $replacements) {
            return $content;
        }

        // Elk <type>-placeholder:<uuid>, zodat een nieuw type geen aanpassing
        // hier meer nodig heeft.
        return preg_replace_callback(
            '/<!--\s*[a-z][a-z0-9_-]*-placeholder:([0-9a-fA-F-]{36})\s*-->/i',
            static function ($matches) use ($replacements) {
                $id = strtolower($matches[1]);

                return isset($replacements[$id]) ? $replacements[$id] : $matches[0];
            },
            $content
        );
    }

    /**
     * Verzamelt de placeholders zonder ze al te renderen, zodat een afbeelding
     * die in meerdere lijsten staat maar een keer wordt opgehaald.
     */
    private static function collect_placeholder_entries($article)
    {
        $legacy_keys = [
            'diagram_placeholders' => 'diagram',
            'diagrams' => 'diagram',
            'diagram_urls' => 'diagram',
            'diagram_image_urls' => 'diagram',
            'illustration_placeholders' => 'illustration',
            'illustrations' => 'illustration',
            'illustration_urls' => 'illustration',
            'illustration_image_urls' => 'illustration',
        ];

        $entries = [];

        foreach ([$article, isset($article['meta']) ? $article['meta'] : null] as $source) {
            if (!is_array($source)) {
                continue;
            }

            // De samengevoegde lijst wint, maar alleen als er ook echt iets in
            // staat: een Engine die het veld al meestuurt maar nog niet vult,
            // levert een lege array en moet op de oude lijsten terugvallen.
            $merged = isset($source['image_placeholders']) ? $source['image_placeholders'] : null;

            if (is_array($merged) && [] !== $merged) {
                $entries = array_merge($entries, self::get_placeholder_entries_from_payload($merged, 'image'));

                continue;
            }

            foreach ($legacy_keys as $key => $type) {
                if (empty($source[$key]) || !is_array($source[$key])) {
                    continue;
                }

                $entries = array_merge($entries, self::get_placeholder_entries_from_payload($source[$key], $type));
            }

            foreach (['diagram' => 'diagram', 'illustration' => 'illustration'] as $prefix => $type) {
                $id = self::get_placeholder_id(
                    isset($source[$prefix . '_id']) ? $source[$prefix . '_id'] : (isset($source[$prefix . '_uuid']) ? $source[$prefix . '_uuid'] : null)
                );
                $url = self::get_string_value(
                    isset($source[$prefix . '_url']) ? $source[$prefix . '_url'] : (isset($source[$prefix . '_image_url']) ? $source[$prefix . '_image_url'] : null)
                );

                if (null !== $id && null !== $url) {
                    $entries[$id] = ['url' => $url, 'type' => $type];
                }
            }
        }

        return $entries;
    }

    private static function get_placeholder_id($value)
    {
        $value = self::get_string_value($value);

        if (null === $value) {
            return null;
        }

        if (1 !== preg_match('/([0-9a-fA-F-]{36})/', $value, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private static function get_placeholder_entries_from_payload($payload, $fallback_type)
    {
        $entries = [];

        foreach ($payload as $key => $entry) {
            if (is_string($key)) {
                $id = self::get_placeholder_id($key);

                if (null === $id) {
                    continue;
                }

                $entry = is_array($entry) ? $entry : ['url' => self::get_string_value($entry)];
            } else {
                if (!is_array($entry)) {
                    continue;
                }

                $id = self::get_placeholder_id(self::first_set($entry, ['placeholder', 'id', 'uuid', 'placeholder_id']));

                if (null === $id) {
                    continue;
                }
            }

            $url = self::get_string_value(self::first_set($entry, ['url', 'image_url', 'diagram_url', 'illustration_url', 'src']));

            if (null === $url) {
                continue;
            }

            $entry['url'] = $url;
            $type = self::normalize_placeholder_type(isset($entry['type']) ? $entry['type'] : null);
            $entry['type'] = null !== $type ? $type : $fallback_type;

            $entries[$id] = $entry;
        }

        return $entries;
    }

    private static function first_set($entry, $keys)
    {
        foreach ($keys as $key) {
            if (isset($entry[$key])) {
                return $entry[$key];
            }
        }

        return null;
    }

    private static function normalize_placeholder_type($value)
    {
        $value = self::get_string_value($value);

        if (null === $value) {
            return null;
        }

        $value = preg_replace('/[^a-z0-9-]/', '', strtolower($value));

        return '' !== (string) $value ? $value : null;
    }

    private static function render_placeholder($entry, $image_url_resolver, $placeholder_id)
    {
        $url = self::get_string_value(isset($entry['url']) ? $entry['url'] : null);

        if (null === $url) {
            return null;
        }

        $type = self::normalize_placeholder_type(isset($entry['type']) ? $entry['type'] : null);
        $type = null !== $type ? $type : 'image';

        $caption = self::get_string_value(isset($entry['caption']) ? $entry['caption'] : null);
        $alt = self::get_string_value(isset($entry['alt']) ? $entry['alt'] : null);
        $alt = null !== $alt ? $alt : $caption;

        if (is_callable($image_url_resolver)) {
            $url = call_user_func($image_url_resolver, $url, $placeholder_id, $entry);
        }

        if (null === $caption && null === $alt) {
            return $url;
        }

        // article-diagram blijft staan voor themes die daar al op stylen.
        return '<figure class="article-image article-' . esc_attr($type) . '">'
            . '<img src="' . esc_url($url) . '" alt="' . esc_attr(null !== $alt ? $alt : '') . '" loading="lazy">'
            . '<figcaption>' . esc_html(null !== $caption ? $caption : '') . '</figcaption>'
            . '</figure>';
    }

    private static function get_string_value($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' !== $value ? $value : null;
    }

    private static function get_category_id()
    {
        $category_name = trim((string) get_option('content_studio_category_name', ''));

        if ('' === $category_name) {
            $category_name = self::get_default_category_name();
        }

        $term = term_exists($category_name, 'category');

        if ($term) {
            return (int) (is_array($term) ? $term['term_id'] : $term);
        }

        $term = wp_insert_term($category_name, 'category', [
            'slug' => sanitize_title($category_name),
        ]);

        if (is_wp_error($term)) {
            return (int) get_option('default_category');
        }

        return (int) $term['term_id'];
    }

    public static function get_default_category_name()
    {
        return 0 === strpos(get_locale(), 'nl_') ? 'Artikelen' : 'Articles';
    }

    private static function get_author_id($article)
    {
        if (empty($article['author_name'])) {
            return self::get_fallback_author_id();
        }

        return self::get_author_id_by_name($article['author_name'], true);
    }

    private static function get_author_id_by_name($author_name, $create)
    {
        if (empty($author_name)) {
            return 0;
        }

        $author_name = sanitize_text_field($author_name);
        $user = get_user_by('login', sanitize_user($author_name));

        if (!$user) {
            $users = get_users([
                'search' => $author_name,
                'search_columns' => ['display_name'],
                'number' => 1,
            ]);

            $user = $users ? $users[0] : null;
        }

        if ($user) {
            return (int) $user->ID;
        }

        if (!$create) {
            return 0;
        }

        $user_id = wp_insert_user([
            'user_login' => self::get_unique_author_login($author_name),
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $author_name,
            'nickname' => $author_name,
            'role' => 'author',
        ]);

        if (is_wp_error($user_id)) {
            return 0;
        }

        return (int) $user_id;
    }

    private static function get_unique_author_login($author_name)
    {
        $base = sanitize_user(strtolower(remove_accents(str_replace(' ', '.', $author_name))), true);

        if (empty($base)) {
            $base = 'content-studio-author';
        }

        $login = $base;
        $suffix = 2;

        while (username_exists($login)) {
            $login = $base . '-' . $suffix;
            $suffix++;
        }

        return $login;
    }

    private static function save_article_meta($post_id, $article)
    {
        $meta_keys = [
            'type',
            'channel',
            'status',
            'content_type',
            'cluster_key',
            'hub_content_id',
            'locale',
            'primary_keyword',
            'meta_description',
            'seo_title',
            'og_title',
            'og_description',
            'twitter_title',
            'twitter_description',
            'featured_image_alt',
            'funnel_stage',
            'intent',
            'angle',
            'author_name',
            'author_role_title',
            'author_experience_label',
            'author_experience_summary',
            'author_article_relevance',
            'author_boundary_note',
            'source_month',
            'planned_at',
            'generated_at',
            'content_hash',
            'updated_at',
        ];

        foreach ($meta_keys as $key) {
            if (!array_key_exists($key, $article)) {
                continue;
            }

            $value = is_scalar($article[$key]) || null === $article[$key]
                ? sanitize_text_field((string) $article[$key])
                : wp_json_encode($article[$key]);

            update_post_meta($post_id, '_content_studio_' . $key, $value);
        }
    }

    private static function get_by_external_id($external_id)
    {
        $posts = get_posts([
            'post_type' => content_studio_post_type(),
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'all',
            'meta_key' => '_content_studio_external_id',
            'meta_value' => $external_id,
        ]);

        return $posts ? $posts[0] : null;
    }

    private static function maybe_import_featured_image($post_id, $image_url, $article)
    {
        if (!$image_url) {
            return;
        }

        $existing_image_url = get_post_meta($post_id, '_content_studio_imported_image_url', true);

        if ($existing_image_url === $image_url && has_post_thumbnail($post_id)) {
            self::set_attachment_author(get_post_thumbnail_id($post_id));
            return;
        }

        $attachment_id = self::import_image($image_url, $article['title'], $post_id);

        if (!$attachment_id) {
            return;
        }

        if (!empty($article['featured_image_alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($article['featured_image_alt']));
        }

        set_post_thumbnail($post_id, $attachment_id);
        update_post_meta($post_id, '_content_studio_imported_image_url', esc_url_raw($image_url));
    }

    private static function maybe_import_placeholder_image($image_url, $article, $post_id, $placeholder_id, $type = 'image')
    {
        if (!$image_url || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            return $image_url;
        }

        $meta_suffix = $placeholder_id ?: md5($image_url);
        $source_meta_key = '_content_studio_imported_diagram_source_url_' . $meta_suffix;
        $local_meta_key = '_content_studio_imported_diagram_local_url_' . $meta_suffix;

        $existing_source_url = get_post_meta($post_id, $source_meta_key, true);
        $existing_local_url = get_post_meta($post_id, $local_meta_key, true);

        if ($existing_source_url === $image_url && $existing_local_url) {
            return $existing_local_url;
        }

        $local_url = self::store_placeholder_file($image_url, $placeholder_id, $type);

        if (!$local_url) {
            return $image_url;
        }

        update_post_meta($post_id, $source_meta_key, esc_url_raw($image_url));
        update_post_meta($post_id, $local_meta_key, esc_url_raw($local_url));

        return $local_url;
    }

    private static function store_placeholder_file($image_url, $placeholder_id, $type = 'image')
    {
        $response = wp_remote_get($image_url, [
            'timeout' => 20,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code < 200 || $status_code >= 300) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);

        if ('' === $body) {
            return '';
        }

        $extension = self::get_image_extension($image_url, wp_remote_retrieve_header($response, 'content-type'));
        // wp_upload_bits schrijft naar de standaard uploadmap, dus het type
        // gaat in de bestandsnaam in plaats van in een submap.
        $prefix = self::normalize_placeholder_type($type);
        $prefix = null !== $prefix ? $prefix . '-' : '';
        $filename = sanitize_file_name($prefix . ($placeholder_id ?: md5($image_url)) . '.' . $extension);
        $upload = wp_upload_bits($filename, null, $body);

        if (!empty($upload['error']) || empty($upload['url'])) {
            return '';
        }

        return esc_url_raw($upload['url']);
    }

    private static function get_image_extension($image_url, $content_type)
    {
        $path = wp_parse_url($image_url, PHP_URL_PATH);
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            return 'jpeg' === $extension ? 'jpg' : $extension;
        }

        $content_type = strtolower((string) $content_type);

        if (false !== strpos($content_type, 'image/jpeg')) {
            return 'jpg';
        }

        if (false !== strpos($content_type, 'image/png')) {
            return 'png';
        }

        if (false !== strpos($content_type, 'image/gif')) {
            return 'gif';
        }

        if (false !== strpos($content_type, 'image/webp')) {
            return 'webp';
        }

        return 'svg';
    }

    private static function get_article_external_id($article)
    {
        foreach (['id', 'uuid', 'slug'] as $key) {
            if (!empty($article[$key])) {
                return sanitize_text_field((string) $article[$key]);
            }
        }

        return '';
    }

    private static function get_article_image_url($article)
    {
        foreach (['featured_image_url', 'image', 'image_url', 'featured_image'] as $key) {
            if (empty($article[$key])) {
                continue;
            }

            if (is_string($article[$key])) {
                return $article[$key];
            }

            if (is_array($article[$key]) && !empty($article[$key]['url'])) {
                return $article[$key]['url'];
            }
        }

        return '';
    }

    private static function import_image($image_url, $title, $post_id)
    {
        if (!$image_url) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image($image_url, $post_id, $title, 'id');

        if (is_wp_error($attachment_id)) {
            update_post_meta($post_id, '_content_studio_image_import_error', $attachment_id->get_error_message());
            return 0;
        }

        delete_post_meta($post_id, '_content_studio_image_import_error');
        self::set_attachment_author($attachment_id);

        return absint($attachment_id);
    }

    private static function set_attachment_author($attachment_id)
    {
        $attachment_id = absint($attachment_id);

        if (!$attachment_id) {
            return;
        }

        $author_id = self::get_content_studio_user_id();

        if (!$author_id) {
            return;
        }

        wp_update_post([
            'ID' => $attachment_id,
            'post_author' => $author_id,
        ]);
    }

    private static function format_datetime($value)
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        if (!$timestamp) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
