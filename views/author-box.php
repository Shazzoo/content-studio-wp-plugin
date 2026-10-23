<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Over de auteur, achter de tekst van een artikel.
 *
 * @var string   $title         Kop in de taal van het artikel.
 * @var string   $author_name
 * @var string   $role          Rol en ervaring, of leeg.
 * @var string[] $intro         Alinea's over de auteur.
 * @var string   $boundary_note Waar de kennis van de auteur ophoudt, of leeg.
 */

?>
<section class="content-studio-author">
    <p class="content-studio-author__label"><?php echo esc_html($title); ?></p>
    <h2 class="content-studio-author__name"><?php echo esc_html($author_name); ?></h2>

    <?php if ('' !== $role) : ?>
        <p class="content-studio-author__role"><?php echo esc_html($role); ?></p>
    <?php endif; ?>

    <?php foreach ($intro as $paragraph) : ?>
        <p class="content-studio-author__intro"><?php echo esc_html($paragraph); ?></p>
    <?php endforeach; ?>

    <?php if ('' !== $boundary_note) : ?>
        <p class="content-studio-author__note"><?php echo esc_html($boundary_note); ?></p>
    <?php endif; ?>
</section>
