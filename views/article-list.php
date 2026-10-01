<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Overzicht van artikelen, voor het Latest Posts-blok en de blogpagina.
 *
 * @var array      $attributes De blokinstellingen (title, showTitle, showImage, ...).
 * @var WP_Query   $posts
 * @var array|null $pagination Alleen op de blogpagina; zie views/pagination.php.
 */

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
                <?php Content_Studio_Latest_Posts_Block::render_card($attributes); ?>
            <?php endwhile; ?>
        </div>
    <?php else : ?>
        <p><?php echo esc_html(Content_Studio_Strings::get('no_articles')); ?></p>
    <?php endif; ?>

    <?php if ($pagination) : ?>
        <?php content_studio_load_view('pagination', ['pagination' => $pagination]); ?>
    <?php endif; ?>
</div>
