<?php

namespace Syspay;

use Exception;

/**
 * Syspay PHP SDK for PHP v7.0+.
 * @link https://app.syspay.com/docs/api/v2/merchant/index.html
 */
class SyspaySDK
{
    const TEST_URL      = 'https://app-sandbox.syspay.com';
    const LIVE_URL      = 'https://app.syspay.com';
    const API_VERSION   = 'v2';

    /**
     * @var $testMode   If set to true, the LIVE_URL is used.
     */
    private $testMode   = false;

    /**
     * @var $debug   If set to true, debug information is logged.
     */
    private $debug      = false;

    /**
     * @var $lastQuery   The last send data for debugging purpose.
     */
    public $lastQuery   = null;

    /**
     * @var $merchantConf   The merchant configuraton object.
     */
    private $merchantConf;

    /**
     * @var $responseHeaders   The request's response headers.
     */
    public static $responseHeaders;

    /**
     * Initialize a Syspay object with merchant credentials.
     */
    public function __construct(MerchantConf $merchantConf)
    {
        if (!$merchantConf->isValid()) {
            throw new Exception('Invalid MerchantConf');
        }
        $this->merchantConf = $merchantConf;
    }

    /**
     * Returns the merchant conf.
     */
    private function getMerchantConf()
    {
        return $this->merchantConf;
    }

    /**
     * Returns Syspay API url.
     */
    public function getUrl($query)
    {
        return ($this->testMode ? self::TEST_URL : self::LIVE_URL) . $query;
    }

    /**
     * Enables test mode.
     */
    public function enableTestMode()
    {
        $this->testMode = true;
    }

    /**
     * Returns Syspay auth headers.
     */
    private function generateHeaders()
    {
        $nonce = md5(rand(), true);
        $timestamp = time();

        $digest = base64_encode(sha1($nonce . $timestamp . $this->getMerchantConf()->getPassphrase(), true));
        $b64nonce = base64_encode($nonce);

        $header = sprintf(
            'X-Wsse: AuthToken MerchantAPILogin="%s", PasswordDigest="%s", Nonce="%s", Created="%d"',
            $this->getMerchantConf()->getLogin(),
            $digest,
            $b64nonce,
            $timestamp
        );

        return $header;
    }

    /**
     * Sends a request to Syspay.
     * 
     * @param string $query The webservice uri.
     * @param array $data   The post data.
     */
    private function sendToSyspay($query, array $data)
    {
        if ($this->debug) {
            error_log(sprintf('Sending to uri: %s data: %s', $query, json_encode($data)));
        }
        $requestBody = json_encode($data);

        $headers     = array(
            'X-Wsse' => $this->generateHeaders(),
        );

        $uri = $this->getUrl($query);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); //timeout in seconds
        array_push($headers, 'Content-Type: application/json');
        array_push($headers, 'Content-Length: ' . strlen($requestBody));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseHeaders = [];

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) // ignore invalid headers
                return $len;

            $responseHeaders[strtolower(trim($header[0]))][] = trim($header[1]);
            return $len;
        });

        $server_output = curl_exec($ch);
        if ($server_output === false) {
            throw new Exception(curl_error($ch), curl_errno($ch));
        }

        self::$responseHeaders = $responseHeaders;

        curl_close($ch);

        $result = json_decode($server_output, true);


        if (json_last_error() != JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in Syspay response: '.$server_output);
        }

        return $result;
    }

    /**
     * Send a payment request.
     * 
     * @param PaymentConf $paymentConf  The request data.
     * @url https://app.syspay.com/docs/api/v2/merchant/api.html#server-to-server-payment-request
     */
    public function sendPayment(PaymentConf $paymentConf)
    {
        $query = sprintf('/api/%s/merchant/payment', self::API_VERSION);

        $map = [
            'flow'          => 'API',
            'source'        => $this->getMerchantConf()->getSourceId(),
            'reference'     => $this->getMerchantConf()->getLogin().time().md5(rand(99999,999999999)),
            'amount'        => $paymentConf->getAmount(),
            'currency'      => 'EUR',
            'description'   => $paymentConf->getDescription(),
            'extra'         => $paymentConf->getExtra(),
            'return_url'    => $paymentConf->getReturnUrl(),
            'ems_url'       => $paymentConf->getEmsUrl(),
            'interactive'   => 1,
            'customer'      => [
                "firstname" => $paymentConf->getCustomerFirstName(),
                "lastname"  => $paymentConf->getCustomerLastName(),
                "email"     => $paymentConf->getCustomerEmail(),
                "language"  => $paymentConf->getCustomerLanguage(),
                "reference" => $paymentConf->getCustomerReference(),
                "ip"        => $this->debug ? $this->getRandomIp() : $paymentConf->getCustomerIP(),
            ],
            'payment_method' => [
                'token_key' => $paymentConf->getTokenKey(),
            ],
        ];

        $map = array_filter($map);

        $this->lastQuery = $map;

        if (($result = $this->sendToSyspay($query, $map))) {
            return new SyspayResponse($result);
        }
    }

    /**
     * Refund a payment.
     * @param RefundConf $paymentConf  The request data.
     * @link https://app.syspay.com/docs/api/v2/merchant/api.html#refund-a-payment
     */
    public function sendRefund(RefundConf $refundConf)
    {
        $query = sprintf('/api/%s/merchant/refund', self::API_VERSION);

        $map = [
            'payment_id'    =>  $refundConf->getPaymentId(),
            'reference'     => $this->getMerchantConf()->getLogin().time().md5(rand(99999,999999999)),
            'amount'        => $refundConf->getAmount(),
            'currency'      => 'EUR',
            'description'   => 'refund',
            'extra'         => '',
            'ems_url'       => $refundConf->getEmsUrl(),
        ];

        $map = array_filter($map);

        if (($result = $this->sendToSyspay($query, $map))) {
            return new SyspayResponse($result);
        }
    }

    /**
     * Returns GET or POST value by key or throws an exception if missing key.
     */
    private function getParam($argName)
    {
        if (isset($_GET[$argName]) && strlen($_GET[$argName]) > 0) {
            return sanitize_text_field($_GET[$argName]);
        }
        if (isset($_POST[$argName]) && strlen($_POST[$argName]) > 0) {
            return sanitize_text_field($_POST[$argName]);
        } else {
            throw new Exception('Missing post process argument: ' . $argName);
        }
    }

    /**
     * Post-process redirection processing.
     * @link https://app.syspay.com/docs/api/v2/merchant/redirection.html
     */
    public function postProcess()
    {
        // Data validation.
        $merchant = $this->getParam('merchant');
        $result   = $this->getParam('result');
        $checksum = $this->getParam('checksum');

        $this->checkInput($merchant, $checksum, $result);

        // $result is a base64-encoded json string
        $result = json_decode(base64_decode($result), true);

        if (json_last_error() == JSON_ERROR_NONE) {
            return $result;
        } else {
            throw new Exception('Post-process invalid JSON');
        }
    }

    /**
     * EMS Notification processing.
     * @link https://app.syspay.com/docs/api/v2/merchant/ems.html
     */
    public function emsProcess()
    {
        
        if(!empty($_GET['merchant']))
        {
            $headerMerchant = $_GET['merchant'];
            $headerChecksum = $_GET['checksum'];
        }
        else
        {
            $headerMerchant = $_SERVER['HTTP_X_MERCHANT'];
            $headerChecksum = $_SERVER['HTTP_X_CHECKSUM'];
        }
        
        
        if(!empty($_GET['result']))
        {
            $body = $_GET['result']; 
            $array = json_decode(base64_decode($body), true);

        }
        else
        {
            $body = file_get_contents('php://input');
            $array = json_decode($body, true);
        }

        if($this->debug){
            print_r($_SERVER);
            error_log('Syspay emsProcess() debug server globals: ' . json_encode($_SERVER));
            // error_log('Syspay emsProcess(): ' . base64_decode($body));
            error_log('Syspay emsProcess(): ' . $body);
        }

        $this->checkInput($headerMerchant, $headerChecksum, $body);
        return $array;
    }

    /**
     * Verifies that input data has been emitted by Syspay.
     */
    private function checkInput($login,$checksum,$data)
    {
        if ($login != $this->getMerchantConf()->getLogin())
            throw new Exception('Invalid post process merchant');

        $shouldBe = sha1($data . $this->getMerchantConf()->getPassphrase());

        if ($checksum !== $shouldBe) {
            throw new Exception('Syspay forge post-process query');
        }
    }

    /**
     * Returns the request headers.
     */
    private function getRequestHeader($key)
    {
        $headers = getallheaders();
        return $headers[$key];
    }

    /**
     * Generate a random IP for testing guidelines.
     */
    private function getRandomIp($publicOnly = true)
    {
        // See http://en.wikipedia.org/wiki/Reserved_IP_addresses
        $reserved = array(
            0, 100, 127, 169, 198, 203, 224, 225, 226, 227, 228, 229, 230, 231,
            232, 233, 234, 235, 236, 237, 238, 239, 240, 241, 242, 243, 244,
            245, 246, 247, 248, 249, 250, 251, 252, 253, 254, 255
        );
        $skip = array_merge($reserved, $publicOnly ? array(10, 172, 192) : array());
        $ip = array();
        do {
            $ip[0] = rand(1, 255);
        } while (in_array($ip[0], $skip));
        $ip[1] = rand(0, 255);
        $ip[2] = rand(0, 255);
        $ip[3] = rand(0, 255);
        return implode('.', $ip);
    }
}

/**
 * Merchant credentials.
 */
class MerchantConf
{
    /**
     * Merchant Syspay login.
     * @var $login
     */
    private $login;

    /**
     * Merchant Syspay passphrase.
     * @var $passphrase
     */
    private $passphrase;

    /**
     * Merchant Syspay public key.
     * @var $publicKey
     */
    private $publicKey;

    /**
     * Merchant Syspay sourceId.
     * @var $sourceId
     */
    private $sourceId = '';

    public function isValid()
    {
        return !empty($this->login) && !empty($this->passphrase) && !empty($this->publicKey);
    }
    public function getLogin()
    {
        return $this->login;
    }
    public function setLogin($val)
    {
        $this->login = $val;
        return $this;
    }

    public function getPassphrase()
    {
        return $this->passphrase;
    }
    public function setPassphrase($val)
    {
        $this->passphrase = $val;
        return $this;
    }

    public function getPublicKey()
    {
        return $this->publicKey;
    }
    public function setPublicKey($val)
    {
        $this->publicKey = $val;
        return $this;
    }

    public function getSourceId()
    {
        return $this->sourceId;
    }
    public function setSourceId($val)
    {
        $this->sourceId = $val;
        return $this;
    }
}

/**
 * Payment request data.
 */
class PaymentConf
{
    /**
     * Payment amount in cents.
     * @var $amount
     */
    private $amount;

    /**
     * Order reference for the payment description.
     * @var $description
     */
    private $description;

    /**
     * Storage for technical data.
     * @var $extra
     */
    private $extra = '';


    private $customerFirstName;
    private $customerLastName;
    private $customerEmail;
    private $customerLanguage;
    private $customerReference;
    private $customerIP;

    /**
     * Url to return to from external workflow.
     * @var $returnUrl
     */
    private $returnUrl;

    /**
     * Notification URI.
     * @var $emsUrl
     */
    private $emsUrl;

    /**
     * Javascript token.
     * @var $tokenKey
     */
    private $tokenKey;

    public function getAmount()
    {
        return $this->amount;
    }
    public function setAmount(int $val)
    {
        $this->amount = $val;
        return $this;
    }

    public function getDescription()
    {
        return $this->description;
    }
    public function setDescription($val)
    {
        $this->description = $val;
        return $this;
    }
    public function getExtra()
    {
        return $this->extra;
    }
    public function setExtra($val)
    {
        $this->extra = $val;
        return $this;
    }
    public function getReturnUrl()
    {
        return $this->returnUrl;
    }
    public function setReturnUrl($val)
    {
        $this->returnUrl = $val;
        return $this;
    }
    public function getEmsUrl()
    {
        return $this->emsUrl;
    }
    public function setEmsUrl($val)
    {
        $this->emsUrl = $val;
        return $this;
    }
    public function getTokenKey()
    {
        return $this->tokenKey;
    }
    public function setTokenKey($val)
    {
        $this->tokenKey = $val;
        return $this;
    }

    public function getCustomerFirstName()
    {
        return $this->customerFirstName;
    }
    public function setCustomerFirstName($val)
    {
        $this->customerFirstName = $val;
        return $this;
    }
    public function getCustomerLastName()
    {
        return $this->customerLastName;
    }
    public function setCustomerLastName($val)
    {
        $this->customerLastName = $val;
        return $this;
    }
    public function getCustomerEmail()
    {
        return $this->customerEmail;
    }
    public function setCustomerEmail($val)
    {
        $this->customerEmail = $val;
        return $this;
    }
    public function getCustomerLanguage()
    {
        return $this->customerLanguage;
    }
    public function setCustomerLanguage($val)
    {
        $this->customerLanguage = $val;
        return $this;
    }
    public function getCustomerReference()
    {
        return $this->customerReference;
    }
    public function setCustomerReference($val)
    {
        $this->customerReference = $val;
        return $this;
    }
    public function getCustomerIP()
    {
        return $this->customerIP;
    }
    public function setCustomerIP($val = '')
    {
        $isPrivateIp = function($a){
            return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        };

        if (!$val){
            $val = $isPrivateIp($_SERVER['REMOTE_ADDR']);
        }
        if (!$val){
            $val = $isPrivateIp($_SERVER['HTTP_X_REAL_IP']);
        }
    
        $this->customerIP = $val;
        return $this;
    }
}

/**
 * Refund request data.
 */
class RefundConf
{
    /**
     * Payment id.
     * @var $paymentId
     */
    private $paymentId;

    /**
     * Refund amount in cents.
     * @var $amount
     */
    private $amount;

    /**
     * Refund description.
     * @var $description
     */
    private $description;

    /**
     * Storage for technical data.
     * @var $extra
     */
    private $extra = '';

    /**
     * Notification URI.
     * @var $emsUrl
     */
    private $emsUrl;

    public function getPaymentId()
    {
        return $this->paymentId;
    }
    public function setPaymentId(int $val)
    {
        $this->paymentId = $val;
        return $this;
    }
    public function getAmount()
    {
        return $this->amount;
    }
    public function setAmount(int $val)
    {
        $this->amount = $val;
        return $this;
    }

    public function getDescription()
    {
        return $this->description;
    }
    public function setDescription($val)
    {
        $this->description = $val;
        return $this;
    }
    public function getExtra()
    {
        return $this->extra;
    }
    public function setExtra($val)
    {
        $this->extra = $val;
        return $this;
    }
    public function getEmsUrl()
    {
        return $this->emsUrl;
    }
    public function setEmsUrl($val)
    {
        $this->emsUrl = $val;
        return $this;
    }
}

/**
 * Syspay payment request response.
 */
class SyspayResponse
{
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get($key)
    {
        if (!isset($this->data[$key]))
            return false;

        return $this->data[$key];
    }
    public function getData()
    {
        return $this->data;
    }
    public function isRedirect()
    {
        return $this->get('status') == TokenStatus::REDIRECT ||
            ($this->get('status') == PaymentStatus::OPEN && $this->get('action_url'));
    }

    public function getActionUrl()
    {
        return $this->get('action_url');
    }

    public function getStatus()
    {
        return $this->get('status');
    }

    public function isStatusFinal()
    {
        return PaymentStatus::isCodeFinal($this->get('status'));
    }

    public function isSuccess()
    {
        return $this->get('status') == PaymentStatus::SUCCESS;
    }

    public function isCodeFinal()
    {
        return PaymentStatus::isCodeFinal($this->get('status'));
    }

    public function getOrderId()
    {
        return $this->get('description');
    }

    public function getTid()
    {
        return $this->get('id');
    }

    public function hasError()
    {
        return $this->get('error_code');
    }

    public function getFailureCategory()
    {
        return $this->get('failure_category');
    }

    public function getErrorMessage()
    {
        $messages = [];
        $messages[] = implode(' ', SyspaySDK::$responseHeaders['x-syspay-request-uuid']);
        foreach ($this->get('errors') as $err) {
            $messages[] = sprintf('%s %s', $err['code'], $err['message']);
        }

        return implode('. ', $messages);
    }
}

/**
 * Statuses and codes.
 * @link https://app.syspay.com/docs/api/v2/merchant/reference/codes.html
 */
class PaymentStatus
{
    const OPEN      = 'OPEN';
    const SUCCESS   = 'SUCCESS';
    const FAILED    = 'FAILED';
    const CANCELLED = 'CANCELLED';
    const ERROR     = 'ERROR';
    const TIMED_OUT = 'TIMED_OUT';

    /**
     * Returns true if code is final.
     */
    public static function isCodeFinal($code)
    {
        $final = [
            self::SUCCESS,
            self::FAILED,
            self::CANCELLED,
            self::ERROR,
            self::TIMED_OUT,
        ];
        return in_array($code, $final);
    }
}

class TokenStatus
{
    const REDIRECT = 'REDIRECT';
}

class ErrorCodes
{
    /**
     * Return message for error code.
     */
    public static function getCodeMessage(int $code)
    {
        $codes =  array(
            10001 => 'Invalid data format',
            10002 => 'Invalid POST data depth',
            10003 => 'Missing required parameters: {{ parameters }}',
            10004 => 'Extra parameters not allowed: {{ parameters }}',
            10005 => 'An unknown error has occurred',
            10006 => 'Missing required parameter',
            10007 => 'Unexpected parameter',
            10008 => 'Conflicting parameters: {{ parameters }}',
            10009 => 'The value {{ value }} is not of type {{ type }}',
            10010 => 'The {{ field }} property is required',
            20001 => 'Invalid amount parameter',
            20002 => 'Invalid currency parameter',
            20003 => 'Invalid start_date parameter',
            20004 => 'Invalid end_date parameter',
            20005 => 'Invalid website parameter',
            20006 => 'Website not found',
            20007 => 'Payment not found',
            20008 => 'The extra parameter has a maximum length of {{ limit }} characters',
            20009 => 'Invalid agent parameter',
            20010 => 'Agent not found',
            20011 => 'Agent is not active',
            20012 => 'Invalid phone number',
            20013 => 'Invalid JSON',
            20014 => 'Invalid First Name',
            20015 => 'Invalid Last Name',
            20016 => 'Invalid business type',
            20017 => 'Invalid phone prefix',
            20018 => 'File not found',
            20019 => 'Resource not found',
            20020 => 'Invalid source parameter',
            20022 => 'Invalid request_time_end parameter',
            20023 => 'Invalid processing_time_start parameter',
            20024 => 'Invalid processing_time_end parameter',
            20025 => 'Invalid token status parameter',
            20026 => 'Invalid expiration_date_start parameter',
            20027 => 'Invalid expiration_date_end parameter',
            20028 => 'Invalid payment_method.type parameter',
            20029 => 'Invalid payment_method.validation_status parameter',
            20030 => 'Invalid page number',
            20031 => 'The unix timestamp value cannot be lower than {{ limit }}',
            20032 => 'The unix timestamp value cannot be higher than {{ limit }}',
            20033 => 'Invalid date parameter',
            20034 => 'Invalid date: check_out_date cannot be lower than check_in_date',
            20035 => 'This parameter has a maximum length of {{ limit }} characters',
            20036 => 'Invalid business phone prefix',
            20037 => 'Invalid business phone number',
            20039 => 'This payment method does not handle refunds.',
            20040 => 'Duplicate request',
            30001 => 'Invalid flow parameter',
            30002 => 'No permission to use this flow: {{ flow }}',
            30003 => 'Invalid billing_agreement parameter',
            30004 => 'No permission to use billing agreements',
            30005 => 'Invalid redirect_url parameter',
            30006 => 'Invalid ems_url parameter',
            30007 => 'Invalid payment reference parameter',
            30008 => 'Payment reference is not unique',
            30009 => 'Invalid preauth parameter',
            30010 => 'No permission to make preauthorizations',
            30011 => 'Invalid payment amount parameter',
            30012 => 'The payment amount parameter should be minimum {{ limit }}',
            30013 => 'The payment amount parameter can be maximum {{ limit }}',
            30014 => 'Unsupported currency parameter',
            30015 => 'Invalid description parameter (maximum {{ limit }} characters)',
            30016 => 'Invalid email parameter',
            30017 => 'Invalid language: {{ value }}',
            30018 => 'Invalid method parameter',
            30019 => 'Invalid ip parameter',
            30020 => 'Invalid cardholder parameter',
            30021 => 'Invalid cardnumber',
            30022 => 'Invalid cvc parameter',
            30023 => 'Invalid exp_month parameter',
            30024 => 'Invalid exp_year parameter',
            30025 => 'Invalid expire date',
            30026 => 'Invalid status parameter',
            30027 => 'The payment currency does not match with the billing agreement currency',
            30028 => 'Invalid threatmetrix_session_id parameter',
            30029 => 'Invalid mode parameter',
            30030 => 'The maximum process limit is exceeded',
            30031 => 'Invalid number of allowed retries',
            30032 => 'Invalid return_url parameter',
            30033 => 'No permission to detokenize as: {{ type }}',
            30034 => 'No permission to make refunds',
            30035 => 'This card scheme is not supported by this merchant.',
            30036 => 'Invalid ideal bank_id',
            30037 => 'Invalid calc_type ({{ calc_type }})',
            30038 => 'The given account_id does not belong to the given user_id',
            30039 => 'Invalid account_id ({{ account_id }})',
            30040 => 'Invalid delay ({{ delay }})',
            30041 => 'The recipient map must be an array of array',
            30042 => 'The sum of each recipient_map amount ({{ sum }}) must be lower than or equal to the total payment amount ({{ max }})',
            30043 => 'Invalid 3DS value',
            30044 => 'Invalid capture date parameter',
            30045 => 'Request must be a preauthorization when entering a capture date',
            30046 => 'Capture date must be more than {{ limit }}',
            30047 => 'Capture date must be less than {{ limit }}',
            30048 => 'Invalid check digit for provided card number',
            30049 => 'Card is wiped or expired',
            40001 => 'Invalid payment_id parameter',
            40002 => 'Invalid refund reference parameter',
            40003 => 'The refund reference parameter is not unique',
            40004 => 'The refund currency parameter does not match with the original payment currency',
            40005 => 'Invalid refund amount parameter',
            40006 => 'The refund amount parameter is too high, the total refund amounts cannot be greater than the original payment amount',
            40007 => 'The refund description parameter has a maximum length of {{ limit }} characters',
            40008 => 'Invalid refund status parameter',
            40009 => 'Refund not found',
            40010 => 'Not enough funds on your account to process this refund',
            50001 => 'The original preauth payment has an invalid status',
            50002 => 'The authorization could not be captured',
            50003 => 'The authorization could not be voided',
            50004 => 'This operation is currently being processed',
            60001 => 'Billing agreement not found',
            60002 => 'Invalid billing agreement status',
            60003 => 'The billing agreement status is not active',
            60004 => 'Maximum number of detokizations reached',
            60005 => 'Invalid Detokenizer ID',
            60006 => 'Invalid authorized_detokenizers IDs',
            60007 => 'Company Reference does not match expected value',
            60008 => 'Unauthorized sevice',
            60009 => 'Detokenization threshold limit reached',
            60010 => 'The mandate could not be activated',
            70001 => 'Chargeback not found',
            80001 => 'Subscription not found',
            80002 => 'Subscription not active',
            90001 => 'Invalid country',
            90002 => 'redirect_url must be given (no default url found)',
            100001 => 'Invalid BIN number',
            100002 => 'BIN number information not found',
            110001 => 'Invalid locked parameter',
            110002 => 'Invalid type parameter',
            110003 => 'Invalid trial period parameter',
            110004 => 'Invalid trial period unit',
            110005 => 'Invalid trial cycles parameter',
            110006 => 'Invalid billing period parameter',
            110007 => 'Invalid billing period unit',
            110008 => 'Invalid billing cycles parameter',
            110009 => 'Invalid API webiste ID',
            110010 => 'Invalid post-process redirect URL',
            110011 => 'Invalid initial charge value',
            110012 => 'Invalid first name',
            110013 => 'Invalid last name',
            110014 => 'Invalid phone number',
            110015 => 'The address parameter can be maximum {{ limit }}',
            110016 => 'The city parameter can be maximum {{ limit }}',
            110017 => 'Invalid amount type parameter',
            110018 => 'Invalid phone prefix',
            110019 => 'Invalid customer country',
            110020 => 'The first name parameter has a maximum length of {{ limit }} characters',
            110021 => 'The last name parameter has a maximum length of {{ limit }} characters',
            110022 => 'Invalid value for preauth',
            110023 => 'The reference parameter has a maximum length of {{ limit }} characters',
            110024 => 'The login parameter has a maximum length of {{ limit }} characters',
            110025 => 'The password hash parameter has a maximum length of {{ limit }} characters',
            120001 => 'Invalid or expired token',
            120002 => 'The data inside the token is invalid',
            120003 => 'Invalid API key',
            120004 => 'Expired token',
            120005 => 'Disabled feature',
            120006 => 'Disabled user',
            130003 => 'Plan not found',
            130004 => 'Invalid plan id',
            130005 => 'Plan type not supported',
            140001 => 'Invalid sex value',
            140002 => 'Invalid user type',
            140003 => 'Invalid user country',
            140004 => 'The company name parameter has a maximum length of 100 characters',
            140005 => 'The business registration number parameter has a maximum length of 100 characters',
            140006 => 'The label parameter has a maximum length of 100 characters',
            140007 => 'The reference parameter has a maximum length of 100 characters',
            140008 => 'JSON format error',
            140009 => 'The provided business type doesnt exist on the provided country',
            140010 => 'Invalid Type on Language',
            140011 => 'Invalid Type on Label',
            140012 => 'Provided referral reference already exists on the system',
            140013 => 'Provided email already on the system',
            140014 => 'Invalid Business Activity',
            140015 => 'Invalid send notify action',
            140016 => 'Invalid username parameter',
            140017 => 'Invalid IP parameter value',
            140018 => 'Invalid Interactive parameter value',
            140019 => 'Invalid known_as map',
            140020 => 'Invalid known_as map: user does not exist',
            140021 => 'Invalid known_as map: different users specified',
            140022 => 'User is already referred by this partner',
            140023 => 'reference-known_as map inconsistency: this user is already known with another reference',
            140024 => 'reference-known_as map inconsistency: the given reference already links to another user',
            140025 => 'Invalid Type on Business name',
            140026 => 'The business name parameter has a maximum length of 100 characters',
            150001 => 'Envelope ID not found',
            160001 => 'Invalid document type',
            160002 => 'Invalid file extension',
            160003 => 'Invalid MIME type',
            170001 => 'Invalid Phone Booking Module operation',
            170002 => 'Invalid description input type',
            170003 => 'Invalid expiration date input type',
            170004 => 'Invalid booking return url',
            170005 => 'Invalid payment page return url',
            170006 => 'Invalid extra input type',
            170007 => 'Invalid detokenizers input type',
            170008 => 'Invalid locked input type',
            170009 => 'Payment not authorized for your service level (serviceName)',
            170010 => 'Invalid gender',
            170011 => 'The last name parameter has a maximum length of {{ limit }} characters',
            170012 => 'Invalid interface language',
            170013 => 'At least one tab must be active on this instance',
            170014 => 'This functionality is not authorized for your service (serviceName)',
            170015 => 'Unknown functionality',
            170016 => 'Unknown input to be locked',
            170017 => 'Email request option is locked due to email input is empty and locked. Please specify an email or allow other options.',
            170018 => 'Invalid locked input value',
            180000 => 'Invalid device type',
        );
        return $codes[$code];
    }
}
