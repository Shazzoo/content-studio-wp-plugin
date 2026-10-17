<?php

/**
 * Of een binnenkomende webhook echt van de engine komt.
 *
 * De handtekening gaat over de tijdstempel én de body samen. Daardoor is een
 * opgevangen aanroep niet later opnieuw te gebruiken met een verse
 * tijdstempel: de handtekening hoort bij precies dat ene moment.
 *
 * De vergelijking gebruikt hash_equals en niet ===, zodat de tijd die het
 * kost niet verraadt hoe ver iemand zat.
 */
class Content_Studio_Webhook_Signature
{
    /** Hoeveel klokverschil tussen engine en site we accepteren. */
    const TOLERANCE_SECONDS = 300;

    /**
     * @param string      $secret
     * @param string|null $timestamp
     * @param string      $body
     * @param string|null $signature
     * @param int|null    $now
     *
     * @return bool
     */
    public static function matches($secret, $timestamp, $body, $signature, $now = null)
    {
        if (!is_string($secret) || '' === $secret) {
            return false;
        }

        if (!is_string($timestamp) || '' === $timestamp || !ctype_digit($timestamp)) {
            return false;
        }

        if (!is_string($signature) || '' === $signature) {
            return false;
        }

        $now = null === $now ? time() : (int) $now;

        if (abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . (string) $body, $secret);

        return hash_equals($expected, $signature);
    }
}
