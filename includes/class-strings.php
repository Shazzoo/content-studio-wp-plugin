<?php

/**
 * De teksten die bezoekers zien, per taal van de artikelen.
 *
 * Een site met artikelen in meerdere talen draait in één WordPress-taal, dus
 * de gewone vertaalbestanden volgen de site en niet het artikel. Daarom kiest
 * deze klasse zelf: de taal van het artikel, of van de blogpagina die
 * getoond wordt. Een taal zonder teksten valt terug op Engels.
 *
 * Aanpassen of aanvullen kan met de filter content_studio_strings.
 */
class Content_Studio_Strings
{
    const STRINGS = [
        'en' => [
            'blog_title' => 'Articles',
            'no_articles' => 'No articles yet...',
            'read_time' => '%d min read',
            'pagination' => 'Pagination',
            'previous_page' => 'Previous page',
            'next_page' => 'Next page',
            'language' => 'Language',
            'hub_title' => 'Cluster overview',
            'hub_intro' => 'Dive into the details with our specialized articles on this topic.',
        ],
        'nl' => [
            'blog_title' => 'Artikelen',
            'no_articles' => 'Nog geen artikelen...',
            'read_time' => '%d min leestijd',
            'pagination' => 'Paginering',
            'previous_page' => 'Vorige pagina',
            'next_page' => 'Volgende pagina',
            'language' => 'Taal',
            'hub_title' => 'Cluster overzicht',
            'hub_intro' => 'Duik in de details met onze gespecialiseerde artikelen over dit onderwerp.',
        ],
        'de' => [
            'blog_title' => 'Artikel',
            'no_articles' => 'Noch keine Artikel...',
            'read_time' => '%d Min. Lesezeit',
            'pagination' => 'Seitennavigation',
            'previous_page' => 'Vorherige Seite',
            'next_page' => 'Nächste Seite',
            'language' => 'Sprache',
            'hub_title' => 'Cluster-Übersicht',
            'hub_intro' => 'Tauchen Sie mit unseren spezialisierten Artikeln zu diesem Thema in die Details ein.',
        ],
    ];

    /**
     * @param string $key
     * @param string $locale Taal van het artikel; leeg voor de taal van de
     *                       pagina die getoond wordt.
     *
     * @return string
     */
    public static function get($key, $locale = '')
    {
        $locale = Content_Studio_Language::normalize('' !== $locale ? $locale : self::page_locale());
        $strings = (array) apply_filters('content_studio_strings', self::STRINGS);

        if (isset($strings[$locale][$key])) {
            return (string) $strings[$locale][$key];
        }

        return isset($strings['en'][$key]) ? (string) $strings['en'][$key] : $key;
    }

    /**
     * De taal die de blog toont; bij 'alle talen' die van de site.
     *
     * @return string
     */
    private static function page_locale()
    {
        $locale = Content_Studio_Language::current();

        return '' === $locale || 'all' === $locale ? get_locale() : $locale;
    }
}
