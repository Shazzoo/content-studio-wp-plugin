<?php

if (!defined('ABSPATH')) {
    exit;
}

$dashboard_card = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'views/dashboard-card.php';
$settings_preview = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'views/settings-preview.php';
$notice = isset($notice) && is_array($notice) ? $notice : null;

function content_studio_render_settings_section($page, $section_id)
{
    global $wp_settings_sections;

    if (!isset($wp_settings_sections[$page][$section_id])) {
        return;
    }

    $section = $wp_settings_sections[$page][$section_id];

    if ($section['title']) {
        echo '<h2>' . esc_html($section['title']) . '</h2>';
    }

    if ($section['callback']) {
        call_user_func($section['callback'], $section);
    }

    echo '<table class="form-table" role="presentation">';
    do_settings_fields($page, $section_id);
    echo '</table>';
}

?>

<?php if ($notice) : ?>
    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
        <p><?php echo esc_html($notice['message']); ?></p>
    </div>
<?php endif; ?>

<div class="wrap">
    <h1>Content Studio</h1>

    <div style="margin: 20px 0 28px;">
        <?php require $dashboard_card; ?>
    </div>

    <form id="content-studio-reset-settings-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <input type="hidden" name="action" value="content_studio_reset_style_settings" />
        <?php wp_nonce_field('content_studio_reset_style_settings'); ?>
    </form>

    <form action="options.php" method="post" class="content-studio-settings-form">
        <?php
        settings_fields('content_studio_settings');
        content_studio_render_settings_section('content-studio', 'content_studio_api_section');
        content_studio_render_settings_section('content-studio', 'content_studio_blog_section');
        content_studio_render_settings_section('content-studio', 'content_studio_language_section');
        ?>

        <div class="content-studio-settings-layout">
            <div class="content-studio-settings-style-fields">
                <?php content_studio_render_settings_section('content-studio', 'content_studio_style_section'); ?>
                <?php submit_button('Save Settings', 'primary content-studio-button'); ?>
            </div>

            <?php require $settings_preview; ?>
        </div>
    </form>
    <hr />
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var button = document.querySelector('.content-studio-toggle-api-key');
        var input = document.getElementById('content_studio_api_key');
        var preview = document.querySelector('.content-studio-settings-preview__blog');
        var advancedRoundingToggle = document.querySelector('.content-studio-advanced-rounding-toggle__checkbox');
        var advancedFontSizeToggle = document.querySelector('.content-studio-advanced-font-size-toggle__checkbox');
        var advancedLineHeightToggle = document.querySelector('.content-studio-advanced-line-height-toggle__checkbox');
        var advancedSpacingToggle = document.querySelector('.content-studio-advanced-spacing-toggle__checkbox');
        var baseRoundingRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-rounding"] input[type="range"]');
        var cardRoundingRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-border-radius"] input[type="range"]');
        var imageRoundingRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-image-border-radius"] input[type="range"]');
        var baseFontSizeRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-font-size"] input[type="range"]');
        var titleFontSizeRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-title-size"] input[type="range"]');
        var metaFontSizeRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-meta-font-size"] input[type="range"]');
        var excerptFontSizeRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-excerpt-font-size"] input[type="range"]');
        var baseLineHeightRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-line-height"] input[type="range"]');
        var titleLineHeightRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-title-line-height"] input[type="range"]');
        var excerptLineHeightRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-excerpt-line-height"] input[type="range"]');
        var metaLineHeightRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-meta-line-height"] input[type="range"]');
        var baseSpacingRange = document.querySelector('.content-studio-range-field[data-css-variable="--content-studio-card-inner-spacing"] input[type="range"]');
        var fontSizeRanges = [{
                range: titleFontSizeRange,
                ratio: 1
            },
            {
                range: excerptFontSizeRange,
                ratio: 2 / 3
            },
            {
                range: metaFontSizeRange,
                ratio: 0.5
            }
        ];
        var roundingRanges = [cardRoundingRange, imageRoundingRange];
        var lineHeightRanges = [titleLineHeightRange, metaLineHeightRange, excerptLineHeightRange];

        function getInternalVariable(cssVariable) {
            return cssVariable.replace('--content-studio-', '--_content-studio-');
        }

        function updatePreviewField(field) {
            if (!preview || !field) {
                return;
            }

            var picker = field.querySelector('.content-studio-color-picker');
            var useTheme = field.querySelector('input[type="checkbox"]');
            var cssVariable = field.getAttribute('data-css-variable');
            var fallbackColor = field.getAttribute('data-fallback-color');
            var color = picker ? picker.value : '';

            if (!cssVariable) {
                return;
            }

            if (useTheme && useTheme.checked) {
                if (fallbackColor) {
                    preview.style.setProperty(cssVariable, fallbackColor);
                    preview.style.setProperty(getInternalVariable(cssVariable), fallbackColor);
                } else {
                    preview.style.removeProperty(cssVariable);
                    preview.style.removeProperty(getInternalVariable(cssVariable));
                }
                return;
            }

            color = color || fallbackColor;

            if (!color) {
                preview.style.removeProperty(cssVariable);
                preview.style.removeProperty(getInternalVariable(cssVariable));
                return;
            }

            preview.style.setProperty(cssVariable, color);
            preview.style.setProperty(getInternalVariable(cssVariable), color);

            if (cssVariable === '--content-studio-color-primary-hover') {
                preview.style.setProperty('--content-studio-color-article-title-hover', color);
            }
        }

        function updatePreviewRange(field) {
            if (!preview || !field) {
                return;
            }

            var range = field.querySelector('input[type="range"]');
            var output = field.querySelector('output');
            var cssVariable = field.getAttribute('data-css-variable');
            var unit = field.hasAttribute('data-unit') ? field.getAttribute('data-unit') : 'px';
            var defaultValue = field.getAttribute('data-default-value');
            var row = field.closest('tr');
            var min = parseFloat(range.min || 0);
            var max = parseFloat(range.max || 100);
            var value = parseFloat(range.value || 0);
            var progress = max > min ? ((value - min) / (max - min)) * 100 : 0;

            if (!range || !cssVariable) {
                return;
            }

            range.style.setProperty('--content-studio-range-progress', progress + '%');

            if (row && row.classList.contains('content-studio-advanced-spacing-row') && !row.classList.contains('is-visible') && range.value === defaultValue) {
                preview.style.removeProperty(cssVariable);
                preview.style.removeProperty(getInternalVariable(cssVariable));
                return;
            }

            preview.style.setProperty(cssVariable, range.value + unit);
            preview.style.setProperty(getInternalVariable(cssVariable), range.value + unit);

            if (output) {
                output.textContent = range.value + unit;
            }
        }

        function updateRangeControl(range, value) {
            var field = range.closest('.content-studio-range-field');
            var min = parseFloat(range.min || 0);
            var max = parseFloat(range.max || 100);
            var step = parseFloat(range.step || 1);
            var nextValue = Math.max(min, Math.min(max, parseFloat(value || 0)));

            range.value = Math.round(nextValue / step) * step;

            if (field) {
                updatePreviewRange(field);
            }
        }

        function getRelativeFontSize(range, value) {
            if (!range) {
                return value;
            }

            var step = parseFloat(range.step || 1);
            var rounded = Math.round(value / step) * step;

            return Math.round(rounded * 100) / 100;
        }

        function syncBaseRoundingRanges() {
            if (!baseRoundingRange || (advancedRoundingToggle && advancedRoundingToggle.checked)) {
                return;
            }

            roundingRanges.forEach(function(range) {
                if (range) {
                    updateRangeControl(range, baseRoundingRange.value);
                }
            });
        }

        function updateAdvancedRoundingState() {
            var isAdvanced = advancedRoundingToggle && advancedRoundingToggle.checked;

            document.querySelectorAll('.content-studio-advanced-rounding-row').forEach(function(row) {
                row.classList.toggle('is-visible', isAdvanced);
            });

            if (baseRoundingRange) {
                baseRoundingRange.disabled = isAdvanced;
                baseRoundingRange.closest('.content-studio-range-field').classList.toggle('is-disabled', isAdvanced);
            }

            if (!isAdvanced) {
                syncBaseRoundingRanges();
            }

            updatePreview();
        }

        function syncBaseFontSizeRanges() {
            if (!baseFontSizeRange || (advancedFontSizeToggle && advancedFontSizeToggle.checked)) {
                return;
            }

            fontSizeRanges.forEach(function(item) {
                if (item.range) {
                    updateRangeControl(item.range, getRelativeFontSize(item.range, parseFloat(baseFontSizeRange.value || 0) * item.ratio));
                }
            });
        }

        function updateAdvancedFontSizeState() {
            var isAdvanced = advancedFontSizeToggle && advancedFontSizeToggle.checked;

            document.querySelectorAll('.content-studio-advanced-font-size-row').forEach(function(row) {
                row.classList.toggle('is-visible', isAdvanced);
            });

            if (baseFontSizeRange) {
                baseFontSizeRange.disabled = isAdvanced;
                baseFontSizeRange.closest('.content-studio-range-field').classList.toggle('is-disabled', isAdvanced);
            }

            if (!isAdvanced) {
                syncBaseFontSizeRanges();
            }

            updatePreview();
        }

        function syncBaseLineHeightRanges() {
            if (!baseLineHeightRange || (advancedLineHeightToggle && advancedLineHeightToggle.checked)) {
                return;
            }

            lineHeightRanges.forEach(function(range) {
                if (range) {
                    updateRangeControl(range, baseLineHeightRange.value);
                }
            });
        }

        function updateAdvancedLineHeightState() {
            var isAdvanced = advancedLineHeightToggle && advancedLineHeightToggle.checked;

            document.querySelectorAll('.content-studio-advanced-line-height-row').forEach(function(row) {
                row.classList.toggle('is-visible', isAdvanced);
            });

            if (baseLineHeightRange) {
                baseLineHeightRange.disabled = isAdvanced;
                baseLineHeightRange.closest('.content-studio-range-field').classList.toggle('is-disabled', isAdvanced);
            }

            if (!isAdvanced) {
                syncBaseLineHeightRanges();
            }

            updatePreview();
        }

        function syncAdvancedSpacingRanges() {
            if (!baseSpacingRange || (advancedSpacingToggle && advancedSpacingToggle.checked)) {
                return;
            }

            document.querySelectorAll('.content-studio-advanced-spacing-row .content-studio-range-field input[type="range"]').forEach(function(range) {
                updateRangeControl(range, baseSpacingRange.value);
            });
        }

        function updateAdvancedSpacingState() {
            var isAdvanced = advancedSpacingToggle && advancedSpacingToggle.checked;

            document.querySelectorAll('.content-studio-advanced-spacing-row').forEach(function(row) {
                row.classList.toggle('is-visible', isAdvanced);
            });

            if (baseSpacingRange) {
                baseSpacingRange.disabled = isAdvanced;
                baseSpacingRange.closest('.content-studio-range-field').classList.toggle('is-disabled', isAdvanced);
            }

            if (!isAdvanced) {
                syncAdvancedSpacingRanges();
            }

            updatePreview();
        }

        function updatePreview() {
            document.querySelectorAll('.content-studio-style-field').forEach(updatePreviewField);
            document.querySelectorAll('.content-studio-range-field').forEach(updatePreviewRange);
        }

        if (window.jQuery && jQuery.fn.wpColorPicker) {
            jQuery('.content-studio-color-picker').wpColorPicker({
                change: function(event, ui) {
                    var picker = event.target;
                    var field = picker.closest('.content-studio-style-field');

                    picker.value = ui.color ? ui.color.toString() : '';
                    updatePreviewField(field);
                },
                clear: function(event) {
                    var field = event.target.closest('.content-studio-style-field');

                    window.setTimeout(function() {
                        updatePreviewField(field);
                    }, 0);
                }
            });
        }

        document.querySelectorAll('.content-studio-style-field input[type="checkbox"], .content-studio-color-picker').forEach(function(control) {
            control.addEventListener('input', updatePreview);
            control.addEventListener('change', updatePreview);
        });

        document.querySelectorAll('.content-studio-range-field input[type="range"]').forEach(function(control) {
            control.addEventListener('input', function() {
                if (control === baseRoundingRange) {
                    syncBaseRoundingRanges();
                }

                if (control === baseFontSizeRange) {
                    syncBaseFontSizeRanges();
                }

                if (control === baseLineHeightRange) {
                    syncBaseLineHeightRanges();
                }

                if (control === baseSpacingRange) {
                    syncAdvancedSpacingRanges();
                }

                updatePreview();
            });
            control.addEventListener('change', function() {
                if (control === baseRoundingRange) {
                    syncBaseRoundingRanges();
                }

                if (control === baseFontSizeRange) {
                    syncBaseFontSizeRanges();
                }

                if (control === baseLineHeightRange) {
                    syncBaseLineHeightRanges();
                }

                if (control === baseSpacingRange) {
                    syncAdvancedSpacingRanges();
                }

                updatePreview();
            });
        });

        if (advancedRoundingToggle) {
            advancedRoundingToggle.addEventListener('change', updateAdvancedRoundingState);

            var roundingSettingsForm = advancedRoundingToggle.closest('form');

            if (roundingSettingsForm && baseRoundingRange) {
                roundingSettingsForm.addEventListener('submit', function() {
                    baseRoundingRange.disabled = false;
                });
            }
        }

        if (advancedFontSizeToggle) {
            advancedFontSizeToggle.addEventListener('change', updateAdvancedFontSizeState);

            var fontSizeSettingsForm = advancedFontSizeToggle.closest('form');

            if (fontSizeSettingsForm && baseFontSizeRange) {
                fontSizeSettingsForm.addEventListener('submit', function() {
                    baseFontSizeRange.disabled = false;
                });
            }
        }

        if (advancedLineHeightToggle) {
            advancedLineHeightToggle.addEventListener('change', updateAdvancedLineHeightState);

            var lineHeightSettingsForm = advancedLineHeightToggle.closest('form');

            if (lineHeightSettingsForm && baseLineHeightRange) {
                lineHeightSettingsForm.addEventListener('submit', function() {
                    baseLineHeightRange.disabled = false;
                });
            }
        }

        if (advancedSpacingToggle) {
            advancedSpacingToggle.addEventListener('change', updateAdvancedSpacingState);

            var settingsForm = advancedSpacingToggle.closest('form');

            if (settingsForm && baseSpacingRange) {
                settingsForm.addEventListener('submit', function() {
                    baseSpacingRange.disabled = false;
                });
            }
        }

        if (advancedSpacingToggle && baseSpacingRange) {
            advancedSpacingToggle.checked = Array.prototype.some.call(
                document.querySelectorAll('.content-studio-advanced-spacing-row .content-studio-range-field input[type="range"]'),
                function(range) {
                    return range.value !== baseSpacingRange.value;
                }
            );
        }

        if (advancedRoundingToggle && baseRoundingRange) {
            advancedRoundingToggle.checked = roundingRanges.some(function(range) {
                return range && range.value !== baseRoundingRange.value;
            });
        }

        if (advancedFontSizeToggle && baseFontSizeRange) {
            advancedFontSizeToggle.checked = fontSizeRanges.some(function(item) {
                return item.range && item.range.value !== String(getRelativeFontSize(item.range, parseFloat(baseFontSizeRange.value || 0) * item.ratio));
            });
        }

        if (advancedLineHeightToggle && baseLineHeightRange) {
            advancedLineHeightToggle.checked = lineHeightRanges.some(function(range) {
                return range && range.value !== baseLineHeightRange.value;
            });
        }

        updateAdvancedRoundingState();
        updateAdvancedFontSizeState();
        updateAdvancedLineHeightState();
        updateAdvancedSpacingState();

        if (button && input) {
            button.addEventListener('click', function() {
                var isVisible = input.type === 'text';
                var icon = button.querySelector('.dashicons');

                input.type = isVisible ? 'password' : 'text';
                button.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                button.setAttribute('aria-label', isVisible ? 'Show API key' : 'Hide API key');

                if (icon) {
                    icon.className = isVisible ? 'dashicons dashicons-visibility' : 'dashicons dashicons-hidden';
                }
            });
        }

        updatePreview();
    });
</script>
