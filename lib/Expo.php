<?php

namespace ExponentPhpSDK;

use CurlHandle;
use ExponentPhpSDK\Exceptions\ExpoException;
use ExponentPhpSDK\Exceptions\UnexpectedResponseException;
use ExponentPhpSDK\Repositories\ExpoFileDriver;

class Expo
{
    /**
     * The Expo Api Url that will receive the requests
     */
    const EXPO_BASE_URL = 'https://exp.host';
    const BASE_API_URL  = self::EXPO_BASE_URL . '/--/api/v2/push';

    /**
     * cURL handler
     *
     * @var CurlHandle|resource
     */
    private CurlHandle $ch;

    /**
     * The registrar instance that manages the tokens
     *
     * @var ExpoRegistrar
     */
    private ExpoRegistrar $registrar;
    
    /**
     * @var string|null
     */
    private string|null $accessToken = null;

    /**
     * Expo constructor.
     *
     * @param ExpoRegistrar $expoRegistrar
     */
    public function __construct(ExpoRegistrar $expoRegistrar)
    {
        $this->registrar = $expoRegistrar;
    }

    /**
     * Creates an instance of this class with the normal setup
     * It uses the ExpoFileDriver as the repository.
     *
     * @return Expo
     */
    public static function normalSetup()
    {
        return new self(new ExpoRegistrar(new ExpoFileDriver()));
    }

    /**
     * Subscribes a given interest to the Expo Push Notifications.
     *
     * @param $interest
     * @param $token
     *
     * @return string
     */
    public function subscribe($interest, $token)
    {
        return $this->registrar->registerInterest($interest, $token);
    }

    /**
     * Unsubscribes a given interest from the Expo Push Notifications.
     *
     * @param $interest
     * @param $token
     *
     * @return bool
     */
    public function unsubscribe($interest, $token = null)
    {
        return $this->registrar->removeInterest($interest, $token);
    }
    
    /**
     * @param string|null $accessToken
     */
    public function setAccessToken(string $accessToken = null)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Send a notification via the Expo Push Notifications Api.
     *
     * @param array $interests
     * @param array $data
     * @param bool $debug
     *
     * @throws ExpoException
     * @throws UnexpectedResponseException
     *
     * @return array|bool
     */
    public function notify(array $interests, array $data, $debug = false)
    {
        $postData = [];

        if (count($interests) == 0) {
            throw new ExpoException('Interests array must not be empty.');
        }

        // Gets the expo tokens for the interests
        $recipients = $this->registrar->getInterests($interests);

        foreach ($recipients as $token) {
            $postData[] = $data + ['to' => $token];
        }

        $ch = $this->prepareCurl('send');

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = $this->executeCurl($ch);

        // Check the integrity of the data returned
        if (! is_array($response)) {
            throw new UnexpectedResponseException(
                $this->handleWithUnexpectedResponse($response)
            );
        }

        // If the notification failed completely, throw an exception with the details
        if ($debug && $this->failedCompletely($response, $recipients)) {
            throw ExpoException::failedCompletelyException($response);
        }

        return $response;
    }

    /**
     * Send a notification via the Expo Push Notifications Api.
     * But this does not depend on the interests. It only expects the message to be sent.
     *
     * @param array $data
     * @param bool $debug
     *
     * @throws ExpoException
     * @throws UnexpectedResponseException
     *
     * @return array
     */
    public function notifyWithMessage(array $data, bool $debug = false)
    {
        // Check if notification is greater than 100
        if (count($data['to']) > 100) {
            throw new ExpoException('PUSH_TOO_MANY_NOTIFICATIONS');
        }
        
        $ch = $this->prepareCurl('send');

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = $this->executeCurl($ch);

        // Check the integrity of the data returned
        if (! is_array($response)) {
            throw new UnexpectedResponseException(
                $this->handleWithUnexpectedResponse($response)
            );
        }

        // If the notification failed completely, throw an exception with the details
        if ($debug && (count($response) !== count($data['to']))) {
            throw ExpoException::failedCompletelyException($response);
        }

        return $response;
    }

     /**
     * Fetch push notification receipts from Expo.
     * Recommended 30 minutes after sending the notification.
     *
     * @param array $receiptsIds
     *
     * @throws ExpoException
     * @throws UnexpectedResponseException
     *
     * @return array
     */
    public function getNotificationReceipts(array $receiptIds)
    {
        $data = ['ids' => $receiptIds];
        // Check if notification is greater than 100
        if (count($receiptIds) > 1000) {
            throw new ExpoException('PUSH_TOO_MANY_RECEIPTS');
        }
        
        $ch = $this->prepareCurl('getReceipts');

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = $this->executeCurl($ch);

        // If the notification failed completely, throw an exception with the details
        if (!is_object($response)) {
            throw new UnexpectedResponseException('Expected Expo to respond with a map from receipt IDs to receipts but received data of another type.');
        }

        return $response;
    }

    /**
     * Chunk notifications into bits of required size
     *
     * @param array $message
     *
     * @return array $chunks
     */
    public function chunkNotifications(array $message, $interests)
    {
        // Ensure the message to property is unset
        unset($message['to']);
        $chunks = [];
        
        // Gets the expo tokens for the interests
        $recipients = $this->registrar->getInterests($interests);

        $count = 0;
        $partialTo = [];
        foreach ($recipients as $token) {
            $partialTo[] = $token;
            $count++;

            if ($count >= 100) {
                $partialMessage = $message + ['to' => $partialTo];
                $chunks[] = $partialMessage;
                $count = 0;
                $partialTo = [];
            }
        }

        if ($count) {
            $partialMessage = $message + ['to' => $partialTo];
            $chunks[] = $partialMessage;
        }

        return $chunks;
    }

    /**
     * Chunk notification's receipt ids to required size
     * 
     * @param array $receiptIds
     * 
     * @return array $receiptChunks
     */
    public function chunkNotificationReceiptIds(array $receiptChunks)
    {
        return array_chunk($receiptChunks, 1000);
    }

    /**
     * Determines if the request we sent has failed completely
     *
     * @param array $response
     * @param array $recipients
     *
     * @return bool
     */
    private function failedCompletely(array $response, array $recipients)
    {
        $numberOfRecipients = count($recipients);
        $numberOfFailures = 0;

        foreach ($response as $item) {
            if ($item['status'] === 'error') {
                $numberOfFailures++;
            }
        }

        return $numberOfFailures === $numberOfRecipients;
    }

    /**
     * Extract ticket ids from and array of tickets
     *
     * @param array $tickets
     * @return array
     */
    public function extractTicketIds(array $tickets) : array
    {
        $ticketIds = [];
        foreach ($tickets as $ticket) {
            if ($ticket['id']) {
                $ticketIds[] = $ticket['id'];
            }
        }

        return $tickets;
    }

    /**
     * Sets the request url and headers
     *
     * @throws ExpoException
     *
     * @return CurlHandle|resource
     */
    private function prepareCurl(string $endpoint)
    {
        $ch = $this->getCurl();

        $headers = [
                'accept: application/json',
                'content-type: application/json',
        ];

        if ($this->accessToken) {
            $headers[] = sprintf('Authorization: Bearer %s', $this->accessToken);
        }

        // Set cURL opts
        curl_setopt($ch, CURLOPT_URL, self::BASE_API_URL.'/'.$endpoint);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return $ch;
    }

    /**
     * Handle with unexpected response error
     *
     * @throws UnexpectedResponseException
     *
     * @return null|resource
     */
    private function handleWithUnexpectedResponse($response)
    {
        if (is_array($response) && isset($response['body'])) {
            $errors = json_decode($response['body'])->errors ?? [];

            return json_encode($errors);
        }

        return null;
    }

    /**
     * Get the cURL resource
     *
     * @throws ExpoException
     *
     * @return CurlHandle|resource
     */
    public function getCurl()
    {
        // Create or reuse existing cURL handle
        $this->ch = $this->ch ?? curl_init();

        // Throw exception if the cURL handle failed
        if (!$this->ch) {
            throw new ExpoException('Could not initialise cURL!');
        }

        return $this->ch;
    }

    /**
     * Executes cURL and captures the response
     *
     * @param $ch
     *
     * @throws UnexpectedResponseException
     *
     * @return array
     */
    private function executeCurl($ch)
    {
        $response = [
            'body' => curl_exec($ch),
            'status_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE)
        ];

        // Check the status code
        // 200, 400, 500
        // {"body":"{\"errors\":[{\"code\":\"VALIDATION_ERROR\",\"message\":\"\\\"value\\\" must be of type object.\",\"isTransient\":false}]}","status_code":400}

        $responseData = json_decode($response['body'], true)['data'] ?? null;

        return $responseData;
    }
}
