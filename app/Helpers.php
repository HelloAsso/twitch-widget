<?php

class Helpers
{
    public static function encryptToken($token)
    {
        // Méthode de chiffrement AES-256-CBC
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encryptedToken = openssl_encrypt($token, 'aes-256-cbc', Config::getInstance()->encryptionKey, 0, $iv);
        // Stocker l'IV avec le token chiffré pour pouvoir le déchiffrer plus tard
        return base64_encode($encryptedToken . '::' . $iv);
    }

    public static function decryptToken($encryptedToken)
    {
        // Décodage base64
        list($encryptedData, $iv) = explode('::', base64_decode($encryptedToken), 2);
        return openssl_decrypt($encryptedData, 'aes-256-cbc', Config::getInstance()->encryptionKey, 0, $iv);
    }

    public static function generateUUID()
    {
        return bin2hex(random_bytes(16));
    }

    public static function generatePKCEChallenge($plainText)
    {
        // Étape 1 : Hacher la chaîne avec SHA-256
        $hashed = hash('sha256', $plainText, true);

        // Étape 2 : Encoder en base64 (sans les =, +, /)
        $base64Encoded = rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');

        return $base64Encoded;
    }

    public static function generateRandomString($length = 80)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomIndex = random_int(0, $charactersLength - 1);
            $randomString .= $characters[$randomIndex];
        }

        return $randomString;
    }
}