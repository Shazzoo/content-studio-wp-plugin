<?php

class Content_Studio_Admin_Settings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_content_studio_sync', [$this, 'sync_articles']);
        add_action('admin_post_content_studio_reset_style_settings', [$this, 'reset_style_settings']);
    }

    public function add_settings_page()
    {
        add_options_page(
            'Content Studio',
            'Content Studio',
            'manage_options',
            'content-studio',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting(
            'content_studio_settings',
            'content_studio_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_project_id',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            Content_Studio_Language::PUBLISHED_OPTION,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_published_locales'],
            ]
        );

        register_setting(
            'content_studio_settings',
            Content_Studio_Language::DEFAULT_CODE_OPTION,
            [
                'type' => 'boolean',
                'sanitize_callback' => static function ($value) {
                    return !empty($value) ? 1 : 0;
                },
                'default' => 1,
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_article_locale',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_article_locale'],
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_category_name',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            Content_Studio_Blog_Route::SLUG_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_blog_slug'],
                'default' => Content_Studio_Blog_Route::DEFAULT_SLUG,
            ]
        );

        // Na de Blog URL, zodat de controle de nieuwe waarde ziet.
        register_setting(
            'content_studio_settings',
            Content_Studio_Blog_Route::SLUG_OVERRIDES_OPTION,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_blog_slug_overrides'],
                'default' => [],
            ]
        );

        register_setting(
            'content_studio_settings',
            Content_Studio_Blog_Route::POSTS_PER_PAGE_OPTION,
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_articles_per_page'],
                'default' => Content_Studio_Blog_Route::DEFAULT_POSTS_PER_PAGE,
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_fallback_author_id',
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_fallback_author_id'],
                'default' => 0,
            ]
        );

        foreach (content_studio_get_style_color_settings() as $option_name => $setting) {
            register_setting(
                'content_studio_settings',
                $option_name,
                [
                    'type' => 'string',
                    'sanitize_callback' => [$this, 'sanitize_color'],
                    'default' => '',
                ]
            );
        }

        foreach (content_studio_get_style_range_settings() as $option_name => $setting) {
            register_setting(
                'content_studio_settings',
                $option_name,
                [
                    'type' => isset($setting['type']) && 'float' === $setting['type'] ? 'number' : 'integer',
                    'sanitize_callback' => function ($value) use ($setting) {
                        return $this->sanitize_range($value, $setting);
                    },
                    'default' => $setting['default'],
                ]
            );
        }

        add_settings_section(
            'content_studio_api_section',
            'Connection Settings',
            [$this, 'render_api_section'],
            'content-studio'
        );

        add_settings_field(
            'content_studio_api_key',
            'API Key',
            [$this, 'render_api_key_field'],
            'content-studio',
            'content_studio_api_section'
        );

        add_settings_field(
            'content_studio_project_id',
            'Project ID',
            [$this, 'render_project_id_field'],
            'content-studio',
            'content_studio_api_section'
        );

        add_settings_section(
            'content_studio_blog_section',
            'Blog Settings',
            [$this, 'render_blog_section'],
            'content-studio'
        );

        add_settings_field(
            Content_Studio_Blog_Route::SLUG_OPTION,
            'Blog URL',
            [$this, 'render_blog_slug_field'],
            'content-studio',
            'content_studio_blog_section'
        );

        add_settings_field(
            Content_Studio_Blog_Route::POSTS_PER_PAGE_OPTION,
            'Articles per Page',
            [$this, 'render_articles_per_page_field'],
            'content-studio',
            'content_studio_blog_section'
        );

        add_settings_field(
            'content_studio_category_name',
            'Article Category',
            [$this, 'render_category_field'],
            'content-studio',
            'content_studio_blog_section'
        );

        add_settings_field(
            'content_studio_fallback_author_id',
            'Fallback Author',
            [$this, 'render_fallback_author_field'],
            'content-studio',
            'content_studio_blog_section'
        );

        add_settings_section(
            'content_studio_language_section',
            'Language Settings',
            [$this, 'render_language_section'],
            'content-studio'
        );

        add_settings_field(
            Content_Studio_Language::PUBLISHED_OPTION,
            'Published Languages',
            [$this, 'render_published_locales_field'],
            'content-studio',
            'content_studio_language_section'
        );

        add_settings_field(
            'content_studio_article_locale',
            'Default Language',
            [$this, 'render_article_locale_field'],
            'content-studio',
            'content_studio_language_section'
        );

        add_settings_field(
            Content_Studio_Language::DEFAULT_CODE_OPTION,
            'Language Code in URLs',
            [$this, 'render_default_code_field'],
            'content-studio',
            'content_studio_language_section'
        );

        add_settings_section(
            'content_studio_style_section',
            'Style Settings',
            [$this, 'render_style_section'],
            'content-studio'
        );

        foreach (content_studio_get_style_color_settings() as $option_name => $setting) {
            add_settings_field(
                $option_name,
                $setting['label'],
                [$this, 'render_color_field'],
                'content-studio',
                'content_studio_style_section',
                [
                    'option_name' => $option_name,
                    'css_variable' => $setting['css_variable'],
                    'default' => isset($setting['default']) ? $setting['default'] : '',
                    'description' => $setting['description'],
                ]
            );
        }

        $advanced_rounding_field_added = false;
        $advanced_font_size_field_added = false;
        $advanced_line_height_field_added = false;
        $advanced_spacing_field_added = false;

        foreach (content_studio_get_style_range_settings() as $option_name => $setting) {
            if (isset($setting['advanced_group']) && 'rounding' === $setting['advanced_group'] && !$advanced_rounding_field_added) {
                add_settings_field(
                    'content_studio_advanced_rounding_toggle',
                    '',
                    [$this, 'render_advanced_rounding_toggle'],
                    'content-studio',
                    'content_studio_style_section'
                );

                $advanced_rounding_field_added = true;
            }

            if (isset($setting['advanced_group']) && 'font-size' === $setting['advanced_group'] && !$advanced_font_size_field_added) {
                add_settings_field(
                    'content_studio_advanced_font_size_toggle',
                    '',
                    [$this, 'render_advanced_font_size_toggle'],
                    'content-studio',
                    'content_studio_style_section'
                );

                $advanced_font_size_field_added = true;
            }

            if (isset($setting['advanced_group']) && 'line-height' === $setting['advanced_group'] && !$advanced_line_height_field_added) {
                add_settings_field(
                    'content_studio_advanced_line_height_toggle',
                    '',
                    [$this, 'render_advanced_line_height_toggle'],
                    'content-studio',
                    'content_studio_style_section'
                );

                $advanced_line_height_field_added = true;
            }

            if (isset($setting['advanced_group']) && 'spacing' === $setting['advanced_group'] && !$advanced_spacing_field_added) {
                add_settings_field(
                    'content_studio_advanced_spacing_toggle',
                    '',
                    [$this, 'render_advanced_spacing_toggle'],
                    'content-studio',
                    'content_studio_style_section'
                );

                $advanced_spacing_field_added = true;
            }

            add_settings_field(
                $option_name,
                $setting['label'],
                [$this, 'render_range_field'],
                'content-studio',
                'content_studio_style_section',
                [
                    'option_name' => $option_name,
                    'css_variable' => $setting['css_variable'],
                    'default' => $setting['default'],
                    'min' => $setting['min'],
                    'max' => $setting['max'],
                    'step' => $setting['step'],
                    'unit' => $setting['unit'],
                    'type' => isset($setting['type']) ? $setting['type'] : 'int',
                    'description' => $setting['description'],
                    'class' => isset($setting['advanced_group']) ? 'content-studio-advanced-' . $setting['advanced_group'] . '-row' : '',
                ]
            );
        }
    }

    public function render_api_key_field()
    {
        printf(
            '<span style="display: inline-flex; align-items: center; gap: 6px;"><input id="content_studio_api_key" type="password" name="content_studio_api_key" value="%s" class="regular-text" autocomplete="new-password" /><button type="button" class="button content-studio-button content-studio-button--icon content-studio-toggle-api-key" aria-label="Show API key" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button></span>',
            esc_attr(get_option('content_studio_api_key', ''))
        );
    }

    public function render_project_id_field()
    {
        printf(
            '<input type="text" name="content_studio_project_id" value="%s" class="regular-text" />',
            esc_attr(get_option('content_studio_project_id', ''))
        );
    }

    public function render_article_locale_field()
    {
        $stored = (string) get_option('content_studio_article_locale', '');
        $published = Content_Studio_Language::published_locales();
        $all = Content_Studio_Language::all_locales() ?: $published;
        $main = Content_Studio_Language::primary_locale();

        // Zonder geldige keuze staat de hoofdtaal geselecteerd, of de eerste
        // gepubliceerde taal als de hoofdtaal niet gepubliceerd is.
        $selected = 'all' === $stored && count($published) > 1 ? 'all' : Content_Studio_Language::default_locale();

        printf('<select name="content_studio_article_locale" id="content_studio_article_locale" class="regular-text" data-main="%s">', esc_attr($main));

        foreach ($all as $locale) {
            printf(
                '<option value="%1$s" data-locale="%1$s"%2$s%3$s>%4$s</option>',
                esc_attr($locale),
                selected($selected, $locale, false),
                disabled(!in_array($locale, $published, true), true, false),
                esc_html(strtoupper($locale) . ($locale === $main ? ' (main language)' : ''))
            );
        }

        printf(
            '<option value="all" data-all="1"%s%s>All published languages</option>',
            selected($selected, 'all', false),
            disabled(count($published) < 2, true, false)
        );

        echo '</select>';

        echo '<p class="description">The language /blog and the Latest Posts block show. Only published languages can be chosen.</p>';

        // Keeps the choices in step with the Published Languages checkboxes
        // before the settings are saved.
        ?>
        <script>
            (function () {
                var select = document.getElementById('content_studio_article_locale');
                var boxes = document.querySelectorAll('input[name="<?php echo esc_js(Content_Studio_Language::PUBLISHED_OPTION); ?>[]"]');

                if (!select || !boxes.length) {
                    return;
                }

                function sync() {
                    var checked = [];

                    boxes.forEach(function (box) {
                        if (box.checked) {
                            checked.push(box.value);
                        }
                    });

                    Array.prototype.forEach.call(select.options, function (option) {
                        if (option.dataset.locale) {
                            option.disabled = checked.indexOf(option.dataset.locale) === -1;
                        } else if (option.dataset.all) {
                            option.disabled = checked.length < 2;
                        }
                    });

                    // A choice that is no longer published moves to the main
                    // language, or else the first published one.
                    if (select.selectedOptions[0] && select.selectedOptions[0].disabled) {
                        select.value = checked.indexOf(select.dataset.main) !== -1 ? select.dataset.main : (checked[0] || '');
                    }
                }

                boxes.forEach(function (box) {
                    box.addEventListener('change', sync);
                });
            })();
        </script>
        <?php
    }

    public function render_published_locales_field()
    {
        $all = Content_Studio_Language::all_locales();
        $published = Content_Studio_Language::published_locales();

        if ([] === $all) {
            echo '<p class="description">The languages appear here after the first sync.</p>';

            return;
        }

        foreach ($all as $locale) {
            printf(
                '<label style="margin-right: 16px;"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label>',
                esc_attr(Content_Studio_Language::PUBLISHED_OPTION),
                esc_attr($locale),
                checked(in_array($locale, $published, true), true, false),
                esc_html(strtoupper($locale))
            );
        }

        echo '<p class="description">Articles in other languages are synced as drafts: not on the site and not confirmed to Content Studio. With one language the URLs have no language code: /blog/{article}.</p>';

        if (!Content_Studio_Language::is_standalone()) {
            echo '<p class="description">Polylang or WPML decides the language URLs; this setting only decides which articles go live.</p>';
        }
    }

    /**
     * At least one language; an empty selection keeps the current one.
     */
    public function sanitize_published_locales($value)
    {
        // No checkboxes on the page yet (no languages known): leave it unset.
        if (null === $value) {
            return get_option(Content_Studio_Language::PUBLISHED_OPTION, null);
        }

        $locales = array_values(array_unique(array_filter(array_map(
            [Content_Studio_Language::class, 'normalize'],
            (array) $value
        ))));

        if ([] === $locales) {
            add_settings_error(Content_Studio_Language::PUBLISHED_OPTION, 'content_studio_published_locales', 'Publish at least one language.');

            return Content_Studio_Language::published_locales();
        }

        return $locales;
    }

    public function render_default_code_field()
    {
        printf(
            '<label><input type="hidden" name="%1$s" value="0" /><input type="checkbox" name="%1$s" value="1"%2$s /> Use the language code for the default language too</label>',
            esc_attr(Content_Studio_Language::DEFAULT_CODE_OPTION),
            checked(Content_Studio_Language::default_code_in_url(), true, false)
        );

        $slug = content_studio_blog_slug();

        printf(
            '<p class="description">Only with more than one published language. On: /nl/%1$s/{article} for every language. Off: /%1$s/{article} for the default language, /en/%1$s/{article} for the others. Old URLs redirect.</p>',
            esc_html($slug)
        );
    }

    /**
     * Saved after the published languages, so a language that was just
     * unpublished falls back to following the main language.
     */
    public function sanitize_article_locale($value)
    {
        $value = trim((string) $value);
        $published = Content_Studio_Language::published_locales();

        if ('' === $value) {
            return $value;
        }

        if ('all' === $value) {
            return count($published) > 1 ? $value : '';
        }

        $locale = Content_Studio_Storage::normalize_locale($value);

        return in_array($locale, $published, true) ? $locale : '';
    }

    public function render_category_field()
    {
        printf(
            '<input type="text" name="content_studio_category_name" value="%s" class="regular-text" placeholder="%s" /> <p class="description">Leave empty to use the default category for the site language.</p>',
            esc_attr(get_option('content_studio_category_name', '')),
            esc_attr(Content_Studio_Storage::get_default_category_name())
        );
    }

    public function render_blog_slug_field()
    {
        $slug = content_studio_blog_slug();

        // Een eigen post type bepaalt zijn URL zelf, via zijn rewrite-slug.
        if ('post' !== content_studio_post_type()) {
            printf(
                '<code>%1$s</code> <p class="description">Set by the post type <code>%2$s</code>; change its rewrite slug to change this URL.</p>',
                esc_html(home_url('/' . $slug)),
                esc_html(content_studio_post_type())
            );

            return;
        }

        printf(
            '<code>%1$s</code><input type="text" name="%2$s" value="%3$s" class="regular-text" style="width: 14em;" /> <p class="description">The blog lives at /%3$s and /{language}/%3$s, articles at /{language}/%3$s/{article}. After a change the old URLs redirect to the new ones.</p>',
            esc_html(trailingslashit(home_url())),
            esc_attr(Content_Studio_Blog_Route::SLUG_OPTION),
            esc_attr($slug)
        );

        $locales = Content_Studio_Language::all_locales();

        // Polylang en WPML vertalen de URL van de pagina zelf.
        if (!Content_Studio_Language::is_standalone() || [] === $locales) {
            return;
        }

        $overrides = Content_Studio_Blog_Route::slug_overrides();

        printf('<details style="margin-top: 12px;"%s><summary style="cursor: pointer;">Advanced: a different URL per language</summary>', [] !== $overrides ? ' open' : '');
        echo '<table role="presentation" style="margin-top: 8px;">';

        foreach ($locales as $locale) {
            printf(
                '<tr><th scope="row" style="padding: 4px 12px 4px 0; width: auto; font-weight: 600;"><label for="content-studio-slug-%1$s">%2$s</label></th><td style="padding: 4px 0;"><code>%3$s</code><input type="text" id="content-studio-slug-%1$s" name="%4$s[%1$s]" value="%5$s" placeholder="%6$s" class="regular-text" style="width: 14em;" /></td></tr>',
                esc_attr($locale),
                esc_html(strtoupper($locale)),
                esc_html(home_url(Content_Studio_Language::url_prefix($locale) . '/')),
                esc_attr(Content_Studio_Blog_Route::SLUG_OVERRIDES_OPTION),
                esc_attr(isset($overrides[$locale]) ? $overrides[$locale] : ''),
                esc_attr($slug)
            );
        }

        echo '</table>';

        printf(
            '<p class="description">Empty uses the Blog URL (/%1$s). For example <code>knowledge</code> for EN gives /en/knowledge and /en/knowledge/{article}, while the other languages keep /%1$s. Old URLs redirect after a change.</p>',
            esc_html($slug)
        );
        echo '</details>';
    }

    /**
     * Per language a URL of its own; empty, or the same as the Blog URL,
     * means none. A URL that clashes keeps the language's previous one.
     */
    public function sanitize_blog_slug_overrides($value)
    {
        if (!is_array($value)) {
            return [];
        }

        $global = content_studio_blog_slug();
        $current = Content_Studio_Blog_Route::slug_overrides();
        $result = [];

        foreach ($value as $locale => $slug) {
            $locale = Content_Studio_Language::normalize((string) $locale);
            $slug = sanitize_title((string) $slug);

            if ('' === $locale || '' === $slug || $slug === $global) {
                continue;
            }

            $error = isset($current[$locale]) && $current[$locale] === $slug ? '' : Content_Studio_Blog_Route::slug_error($slug);

            if ('' !== $error) {
                add_settings_error(Content_Studio_Blog_Route::SLUG_OVERRIDES_OPTION, 'content_studio_blog_slug_' . $locale, strtoupper($locale) . ': ' . $error);

                if (isset($current[$locale])) {
                    $result[$locale] = $current[$locale];
                }

                continue;
            }

            $result[$locale] = $slug;
        }

        return $result;
    }

    /**
     * Keeps the current URL and shows why when the new one would clash with
     * a page, a language code or a WordPress path.
     */
    public function sanitize_blog_slug($value)
    {
        $slug = sanitize_title((string) $value);

        if ('' === $slug) {
            $slug = Content_Studio_Blog_Route::DEFAULT_SLUG;
        }

        $error = Content_Studio_Blog_Route::slug_error($slug);

        if ('' !== $error) {
            add_settings_error(Content_Studio_Blog_Route::SLUG_OPTION, 'content_studio_blog_slug', $error);

            return content_studio_blog_slug();
        }

        return $slug;
    }

    public function render_articles_per_page_field()
    {
        printf(
            '<input type="number" name="%1$s" value="%2$d" min="1" max="%3$d" class="small-text" /> <p class="description">How many articles the blog page shows before it moves to page 2.</p>',
            esc_attr(Content_Studio_Blog_Route::POSTS_PER_PAGE_OPTION),
            Content_Studio_Blog_Route::posts_per_page(),
            Content_Studio_Blog_Route::MAX_POSTS_PER_PAGE
        );
    }

    public function sanitize_articles_per_page($value)
    {
        return max(1, min(Content_Studio_Blog_Route::MAX_POSTS_PER_PAGE, absint($value)));
    }

    public function render_fallback_author_field()
    {
        $selected_user_id = Content_Studio_Storage::get_fallback_author_id();

        wp_dropdown_users([
            'name' => 'content_studio_fallback_author_id',
            'id' => 'content_studio_fallback_author_id',
            'selected' => $selected_user_id,
            'show' => 'display_name_with_login',
            'class' => 'regular-text',
        ]);

        echo '<p class="description">Used when an article from the API does not include an author.</p>';
    }

    public function render_api_section()
    {
        echo '<p>Connect the plugin to your Content Studio project.</p>';
    }

    public function render_blog_section()
    {
        echo '<p>Where the articles live on this site and how they are stored.</p>';
    }

    public function render_language_section()
    {
        echo '<p>Which of the project\'s languages go live, and how their URLs look.</p>';
    }

    public function render_style_section()
    {
        echo '<p>Use the controls below to style generated article cards.</p>';
    }

    public function render_color_field($args)
    {
        $option_name = $args['option_name'];
        $css_variable = $args['css_variable'];
        $default = $args['default'];
        $description = $args['description'];
        $value = get_option($option_name, $default);

        printf(
            '<span class="content-studio-style-field" data-css-variable="%4$s" data-fallback-color="%5$s"><input type="text" name="%1$s" value="%2$s" class="content-studio-color-picker" data-default-color="" /></span><p class="description">%3$s</p>',
            esc_attr($option_name),
            esc_attr($value),
            esc_html($description),
            esc_attr($css_variable),
            esc_attr($default)
        );
    }

    public function render_range_field($args)
    {
        $option_name = $args['option_name'];
        $is_float = isset($args['type']) && 'float' === $args['type'];
        $value = $is_float ? (float) get_option($option_name, $args['default']) : absint(get_option($option_name, $args['default']));
        $value = max($args['min'], min($args['max'], $value));

        printf(
            '<span class="content-studio-range-field" data-css-variable="%6$s" data-unit="%7$s" data-default-value="%9$s"><input type="range" name="%1$s" value="%2$s" min="%3$s" max="%4$s" step="%5$s" /><output>%2$s%7$s</output></span><p class="description">%8$s</p>',
            esc_attr($option_name),
            esc_attr($value),
            esc_attr($args['min']),
            esc_attr($args['max']),
            esc_attr($args['step']),
            esc_attr($args['css_variable']),
            esc_html($args['unit']),
            esc_html($args['description']),
            esc_attr($args['default'])
        );
    }

    public function render_advanced_spacing_toggle()
    {
        echo '<label class="content-studio-advanced-spacing-toggle"><input type="checkbox" class="content-studio-advanced-spacing-toggle__checkbox" /> Advanced spacing</label>';
    }

    public function render_advanced_rounding_toggle()
    {
        echo '<label class="content-studio-advanced-rounding-toggle"><input type="checkbox" class="content-studio-advanced-rounding-toggle__checkbox" /> Advanced rounding</label>';
    }

    public function render_advanced_font_size_toggle()
    {
        echo '<label class="content-studio-advanced-font-size-toggle"><input type="checkbox" class="content-studio-advanced-font-size-toggle__checkbox" /> Advanced font sizing</label>';
    }

    public function render_advanced_line_height_toggle()
    {
        echo '<label class="content-studio-advanced-line-height-toggle"><input type="checkbox" class="content-studio-advanced-line-height-toggle__checkbox" /> Advanced line height</label>';
    }

    public function sanitize_fallback_author_id($user_id)
    {
        $user_id = absint($user_id);

        return get_user_by('id', $user_id) ? $user_id : Content_Studio_Storage::get_content_studio_user_id();
    }

    public function sanitize_color($color)
    {
        $color = trim((string) $color);

        if ('' === $color) {
            return '';
        }

        return sanitize_hex_color($color) ?: '';
    }

    public function sanitize_range($value, $setting)
    {
        $value = isset($setting['type']) && 'float' === $setting['type'] ? (float) $value : absint($value);

        return max($setting['min'], min($setting['max'], $value));
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $reset_result = isset($_GET['content_studio_reset']) ? sanitize_text_field(wp_unslash($_GET['content_studio_reset'])) : '';
        $sync_result = isset($_GET['content_studio_sync']) ? sanitize_text_field(wp_unslash($_GET['content_studio_sync'])) : '';
        $notice = null;

        if ('success' === $reset_result) {
            $notice = [
                'type' => 'success',
                'message' => 'Style settings reset to defaults.',
            ];
        } elseif ('success' === $sync_result) {
            $saved = isset($_GET['saved']) ? absint($_GET['saved']) : 0;
            $notice = [
                'type' => 'success',
                'message' => sprintf('Content Studio synced %d article(s).', $saved),
            ];
        } elseif ('error' === $sync_result) {
            $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : 'Sync failed.';
            $notice = [
                'type' => 'error',
                'message' => $message,
            ];
        }

        $count = Content_Studio_Storage::count_posts();

        require plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'views/admin-settings.php';
    }

    public function reset_style_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sorry, you are not allowed to reset Content Studio settings.');
        }

        check_admin_referer('content_studio_reset_style_settings');

        foreach (content_studio_get_style_color_settings() as $option_name => $setting) {
            delete_option($option_name);
        }

        foreach (content_studio_get_style_range_settings() as $option_name => $setting) {
            delete_option($option_name);
        }

        wp_safe_redirect(
            add_query_arg(
                'content_studio_reset',
                'success',
                admin_url('options-general.php?page=content-studio')
            )
        );
        exit;
    }

    public function sync_articles()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sorry, you are not allowed to sync Content Studio articles.');
        }

        check_admin_referer('content_studio_sync');

        $redirect_url = admin_url('options-general.php?page=content-studio');
        $result = self::run_sync();

        if (is_wp_error($result)) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'content_studio_sync' => 'error',
                        'message' => $result->get_error_message(),
                    ],
                    $redirect_url
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'content_studio_sync' => 'success',
                    'saved' => $result,
                ],
                $redirect_url
            )
        );
        exit;
    }

    /**
     * Synct en legt de uitkomst vast voor de status op de instellingenpagina,
     * of de sync nu van de cron, de knop of de REST-route komt.
     *
     * @return int|WP_Error Aantal opgeslagen artikelen.
     */
    public static function run_sync()
    {
        $result = self::sync();

        update_option('content_studio_last_sync_at', time());

        if (is_wp_error($result)) {
            update_option('content_studio_last_sync_error', $result->get_error_message());

            return $result;
        }

        update_option('content_studio_last_sync_error', '');
        update_option('content_studio_last_sync_count', absint($result));

        return $result;
    }

    /**
     * Wat de statuskaart toont.
     *
     * @return array
     */
    public static function sync_status()
    {
        $last_at = get_option('content_studio_last_sync_at', '');

        // Oudere versies sloegen de lokale tijd als tekst op.
        if ('' !== $last_at && !is_numeric($last_at)) {
            $last_at = strtotime(get_gmt_from_date((string) $last_at) . ' UTC');
        }

        $next_at = wp_next_scheduled(CONTENT_STUDIO_SYNC_CRON_EVENT);

        return [
            'last_at' => $last_at ? (int) $last_at : null,
            'last_error' => (string) get_option('content_studio_last_sync_error', ''),
            'last_count' => absint(get_option('content_studio_last_sync_count', 0)),
            'next_at' => $next_at ? (int) $next_at : null,
            // Een kwartier te laat betekent dat WP-Cron niet draait: de site
            // krijgt geen bezoek, of DISABLE_WP_CRON staat aan zonder echte cron.
            'cron_overdue' => $next_at && $next_at < time() - 15 * MINUTE_IN_SECONDS,
            'confirmation_error' => (string) get_option('content_studio_last_publish_confirmation_error', ''),
        ];
    }

    /**
     * @return int|WP_Error
     */
    private static function sync()
    {
        // Zonder deze controle belanden de artikelen onder een post type dat
        // nergens getoond wordt, bijvoorbeeld als de plugin die het
        // registreert uit staat.
        if (!post_type_exists(content_studio_post_type())) {
            return new WP_Error(
                'content_studio_unknown_post_type',
                sprintf('Post type "%s" is not registered, so Content Studio did not sync.', content_studio_post_type())
            );
        }

        $content = Content_Studio_API_Client::fetch_content();

        if (is_wp_error($content)) {
            return $content;
        }

        return Content_Studio_Storage::save($content);
    }
}
