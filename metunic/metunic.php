<?php
/**
 * WHMCS SDK Sample Registrar Module
 *
 * Registrar Modules allow you to create modules that allow for domain
 * registration, management, transfers, and other functionality within
 * WHMCS.
 *
 * This sample file demonstrates how a registrar module for WHMCS should
 * be structured and exercises supported functionality.
 *
 * Registrar Modules are stored in a unique directory within the
 * modules/registrars/ directory that matches the module's unique name.
 * This name should be all lowercase, containing only letters and numbers,
 * and always start with a letter.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For
 * example this file, the filename is "metunic.php" and therefore all
 * function begin "metunic_".
 *
 * If your module or third party API does not support a given function, you
 * should not define the function within your module. WHMCS recommends that
 * all registrar modules implement Register, Transfer, Renew, GetNameservers,
 * SaveNameservers, GetContactDetails & SaveContactDetails.
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/domain-registrars/
 *
 * @copyright Copyright (c) WHMCS Limited 2017
 * @license https://www.whmcs.com/license/ WHMCS Eula
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Carbon;
use WHMCS\Database\Capsule;
// Require any libraries needed for the module to function.
// require_once __DIR__ . '/path/to/library/loader.php';
//
// Also, perform any initialization required by the service's library.

/**
 * Define module related metadata
 *
 * Provide some module information including the display name and API Version to
 * determine the method of decoding the input values.
 *
 * @return array
 */
function metunic_MetaData()
{
    return array(
        'DisplayName' => 'Metunic Registrar Module for WHMCS',
        'APIVersion' => '1.0',
    );
}

/**
 * Define registrar configuration options.
 *
 * The values you return here define what configuration options
 * we store for the module. These values are made available to
 * each module function.
 *
 * You can store an unlimited number of configuration settings.
 * The following field types are supported:
 *  * Text
 *  * Password
 *  * Yes/No Checkboxes
 *  * Dropdown Menus
 *  * Radio Buttons
 *  * Text Areas
 *
 * @return array
 */
function metunic_getConfigArray()
{
    return [
        // Friendly display name for the module
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Metunic Registrar Module for WHMCS',
        ],
        // a text field type allows for single line text input
        'APIUsername' => [
            'FriendlyName' => 'API Username',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter in megabytes',
        ],
        // a password field type allows for masked text input
        'APIKey' => [
            'FriendlyName' => 'API Password',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter secret value here',
        ],
    ];
}



function logToFile($message) {
        $logFile = '/tmp/debug_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = $timestamp . ': ' . $message . PHP_EOL;

        // Append the log message to the log file
        file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function sendRequest($method, $endpoint, $queryParams = [], $username, $password) {
    $baseURL = 'https://api-test.metunic.com.tr/v1';
    $loginEndpoint = $baseURL . '/login/auth';
    $requestEndpoint = $baseURL . $endpoint;

    $ch = curl_init($loginEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $username,
        'password' => $password
    ]));
    curl_setopt($ch, CURLOPT_HEADER, true); // Include response headers
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);
    if ($info['http_code'] !== 200) {
        return array('error' => $responseData['messageText']);
    }

    // Extract the cookie from the response headers
    $headers = substr($response, 0, $info['header_size']);
    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $headers, $cookie);
    $cookieValue = $cookie[1];

    // Append query parameters to the request URL
    if (!empty($queryParams)) {
        $requestEndpoint .= '?' . http_build_query($queryParams);
    }

    // Send the actual request with the authenticated cookie
    $ch = curl_init($requestEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookieValue);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    }
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_PUT, true);
    }

    $response = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);
    return $responseData;
}

function metunic_GetTldPricing($params) {
    $username = $params['APIUsername'];
    $password = $params['APIKey'];
    $tld = "com.tr";
    $queryParams = array(
        'tld' => $tld,
        'duration' => 1
    );


    $responseData = sendRequest(GET, "/pricings/pricings-tld", $queryParams, $username, $password);
    $results = new ResultsList();
    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        $term = $responseData['result']['term'];
        $price = $responseData['result']['price'];
        $renewPrice = $responseData['result']['priceRenews'];
        $transferPrice = $responseData['result']['priceTransfer'];
        $currency = $responseData['result']['currency'];
        $packageName = $responseData['result']['packageName'];

        $importItem = new ImportItem();
                $importItem->setExtension('.'.$tld);
                $importItem->setMinYears('1');
                $importItem->setMaxYears('5');
                $importItem->setRegisterPrice($price);
                $importItem->setRenewPrice($renewPrice);
                $importItem->setTransferPrice($transferPrice);
                $importItem->setCurrency($currency);
        $results->append($importItem);
        return $results;
    } else {
        return array('error' => $responseData['messageText']);
    }
}

function metunic_RegisterDomain($params) {
    $username = $params['APIUsername'];
    $password = $params['APIKey'];
    $queryParams = array(
        'registrant_type' => "individual",
        'registrant_name' => $params['fullname'],
        'registrant_citizen_id' => $params['customfields1'],
        'registrant_address1' => $params['address1'],
        'registrant_address2' => $params['address2'],
        'registrant_country' => "215",
        'registrant_city' => "20",
        'registrant_postal_code' => $params['postcode'],
        'registrant_phone' => "+" . $params['phonencc'] . $params['phonenumber'],
        'registrant_email_address' => $params['email'],
        'domain' => $params['domain'],
        'ns1' => $params['ns1'],
        'ns2' => $params['ns2'],
        'duration' => $params['regperiod']
    );
    $responseData = sendRequest(POST, "/orders/tr", $queryParams, $username, $password);

    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        return array('success' => true);
    } else {
        return array('error' => $responseData['result']);
    }
}


function metunic_TransferDomain($params){
    $username = $params['APIUsername'];
    $password = $params['APIKey'];
    $queryParams = array(
        'auth' => $params['eppcode'],
        'domain' => $params['domain']
    );
    $responseData = sendRequest(POST, "/transfers/tr/add", $queryParams, $username, $password);

    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        return array('success' => true);
    } else {
        return array('error' => $responseData['result']);
    }
}


function metunic_RenewDomain($params){
    $username = $params['APIUsername'];
    $password = $params['APIKey'];

    $queryParams = array(
        'domainName' => $params['domain']
    );

    $responseData = sendRequest(GET, "/services/queried-services", $queryParams, $username, $password);
    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        $serviceId = $responseData['result']['id'];
    } else {
        return array('error' => $responseData['result']);
    }
    $queryParams = array(
        'duration' => $params['regperiod']
    );
    $responseData = sendRequest(POST, "/services/" . $serviceId . "/renew-duration", $queryParams, $username, $password);

    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        return array('success' => true);
    } else {
        return array('error' => $responseData['result']);
    }

}

function metunic_Sync($params){
    $username = $params['APIUsername'];
    $password = $params['APIKey'];

    $queryParams = array(
        'domainName' => $params['domain']
    );
    $responseData = sendRequest(GET, "/services/queried-services", $queryParams, $username, $password);
    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        $expiryDate = new DateTime($responseData['result']['dateRenews']);
        $formattedExpiryDate = $expiryDate->format('Y-m-d');

        $status = $responseData['result']['status'];
        if ($status == 'active') {
            Capsule::table('tbldomains')->where('domain', '=', $params['domain'])->update(['status' => 'Active']);
        } else {
            Capsule::table('tbldomains')->where('domain', '=', $params['domain'])->update(['status' => 'Expired']);
        }
        if ($expiryDate) {
            Capsule::table('tbldomains')->where('domain', '=', $params['domain'])->update(['expirydate' => $formattedExpiryDate]);
            Capsule::table('tbldomains')->where('domain', '=', $params['domain'])->update(['nextduedate' => $formattedExpiryDate]);
        }
        return array(
                'success' => true,
                'message' => "Domain synced succesfully, please refresh the page."
        );
    } else {
        return array('error' => $responseData['messageText']);
    }
}

function metunic_GetNameservers($params){
    $username = $params['APIUsername'];
    $password = $params['APIKey'];

    $queryParams = array(
        'domainName' => $params['domain']
    );

    $responseData = sendRequest(GET, "/services/queried-services", $queryParams, $username, $password);
    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        $serviceId = $responseData['result']['id'];
    } else {
        return array('error' => $responseData['result']);
    }
    $queryParams = array(

    );
    $responseData = sendRequest(GET, "/services/" . $serviceId . "/tr/nameservers/list", $queryParams, $username, $password);
    $result = $responseData['result'];

    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {

        return array(
            'ns1' => $result['ns'][0],
            'ns2' => $result['ns'][1],
            'ns3' => $result['ns'][2],
            'ns4' => $result['ns'][3],
            'ns5' => $result['ns'][4]
        );
    } else {
        return array('error' => $responseData['messageText']);
    }

}

function metunic_SaveNameservers($params){
    $username = $params['APIUsername'];
    $password = $params['APIKey'];

    $queryParams = array(
        'domainName' => $params['domain']
    );

    $responseData = sendRequest(GET, "/services/queried-services", $queryParams, $username, $password);
    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {
        $serviceId = $responseData['result']['id'];
    } else {
        return array('error' => $responseData['messageText']);
    }
    $queryParams = array(
        'ns1' => $params['ns1'],
        'ns2' => $params['ns2'],
        'ns3' => $params['ns3'],
        'ns4' => $params['ns4'],
        'ns5' => $params['ns5'],
    );
    $responseData = sendRequest(PUT, "/services/" . $serviceId . "/tr/nameservers/change", $queryParams, $username, $password);
    if (isset($responseData['messageCode']) && $responseData['messageCode'] === 1) {

        return array(
                'success' => true,
        );
    } else {
        return array('error' => $responseData['messageText']);
    }

}



function metunic_AdminCustomButtonArray() {
        $buttonarray = array(
             "Sync" => "Sync",
        );
        return $buttonarray;
}
