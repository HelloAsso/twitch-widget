<?php

namespace App\Services;

/**
 * Envoi d'emails transactionnels.
 */
interface Mailer
{
    /**
     * Envoie un email HTML à un destinataire.
     *
     * @throws \RuntimeException si l'email n'a pas pu être envoyé (configuration manquante, rejet du fournisseur, erreur réseau)
     */
    public function send(string $to, string $subject, string $html): void;
}
