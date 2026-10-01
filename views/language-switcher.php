<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taalkeuze voor de blog, alleen zonder Polylang of WPML.
 *
 * @var array $languages Per taal: locale, url en current.
 */

?>
<nav class="content-studio-language-switcher" aria-label="<?php echo esc_attr(Content_Studio_Strings::get('language')); ?>">
    <ul class="content-studio-language-switcher__list">
        <?php foreach ($languages as $language) : ?>
            <li class="content-studio-language-switcher__item">
                <a class="content-studio-language-switcher__link<?php echo $language['current'] ? ' is-current' : ''; ?>" href="<?php echo esc_url($language['url']); ?>"<?php echo $language['current'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html(strtoupper($language['locale'])); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
