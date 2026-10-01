<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * De artikelen onder een hub-artikel, achter de tekst van dat artikel.
 *
 * @var string   $title      Kop in de taal van het artikel.
 * @var string   $intro      Inleiding in de taal van het artikel.
 * @var WP_Query $articles
 * @var array    $attributes Kaartinstellingen voor render_card().
 */

?>
<section class="content-studio-blog content-studio-hub-articles">
    <header class="content-studio-blog__header">
        <h2 class="content-studio-blog__title"><?php echo esc_html($title); ?></h2>
        <p class="content-studio-hub-articles__intro"><?php echo esc_html($intro); ?></p>
    </header>

    <div class="content-studio-blog__grid">
        <?php while ($articles->have_posts()) : $articles->the_post(); ?>
            <?php Content_Studio_Latest_Posts_Block::render_card($attributes); ?>
        <?php endwhile; ?>
    </div>
</section>
