<?php
require 'config.php';

function GetDonationFormOrders($organizationSlug, $donationSlug, $accessToken, $continuationToken = null)
{
    $curl = curl_init();
    
    // Construire l'URL avec ou sans continuationToken
    $url = $_SESSION['api_url'] . '/organizations/' . $organizationSlug . '/forms/donation/' . $donationSlug . '/orders';
    if ($continuationToken) {
        $url .= '?continuationToken=' . $continuationToken;
    }

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $accessToken
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $response_data = json_decode($response, true);
    return $response_data;
}