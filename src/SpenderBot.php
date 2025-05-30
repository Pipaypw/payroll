<?php

namespace Pipaypw\Payroll;

use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Util\FriendBot;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Asset;

use Soneso\StellarSDK\CreateAccountOperationBuilder;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\FeeBumpTransactionBuilder;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\TimeBounds;

use GuzzleHttp\Client; 

class SpenderBot{
	private $api_key;
	private $escrowSeed;
    private $destination;

	private $httpClient;
    private $currentPayment;

    public  $sdk;
    public  $network;
    public  $owner;

	public function __construct($network="Pi Network", $api_key, $escrowSeed, $destination)
	{
		$this->api_key = $api_key;
		$this->escrowSeed = $escrowSeed;
        $this->network = $network;
        $this->sdk = $this->getHorizonClient($this->network);
        $this->owner = KeyPair::fromSeed($this->escrowSeed);
        $this->destination = $destination;

		$this->httpClient = new Client([
            'base_uri' => "https://api.minepi.com",
            'exceptions' => false,
            'verify' => false
        ]);
	}

    public function getHorizonClient($network)
    {
        $serverUrl = $network === "Pi Network" ? "https://api.mainnet.minepi.com" : "https://api.testnet.minepi.com";
        $sdk = new StellarSDK($serverUrl);
        return $sdk;
    }

    public function getFeeRate(){
        $responseFeeStats = $this->sdk->requestFeeStats();
        return $responseFeeStats->getLastLedgerBaseFee();
    }

    public function getDestination(){
        return $this->destination;
    }

    public function getOwner(){
        return $this->owner->getAccountId();
    }

	public function createPayment($paymentData)
	{
        try {
    		$rep = $this->httpClient->request('POST', '/v2/payments', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Key '.$this->api_key
                ],
                'json' => $paymentData
            ]);
            $body = $rep->getBody();
            $body_obj = json_decode($body, false, 512, JSON_UNESCAPED_UNICODE);
            return $body_obj->identifier;
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
	}

    public function getPayment($paymentId)
    {
        $rep = $this->httpClient->request('GET', '/v2/payments/'.$paymentId, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Key '.$this->api_key
            ],
        ]);
        $body = $rep->getBody();
        $body_obj = json_decode($body, false, 512, JSON_UNESCAPED_UNICODE);
        return $body_obj;
    }

    public function submitPayment(string $paymentId)
    {
        if (!$this->currentPayment || $this->currentPayment->identifier !== $paymentId) {
            $this->currentPayment = $this->getPayment($paymentId);
            $txid = $this->currentPayment->transaction->txid ?? null;
            if ($txid) {
                throw new \Exception(json_encode([
                    'message' => 'This payment already has a linked txid',
                    'paymentId' => $paymentId,
                    'txid' => $txid
                ]));
            }
        }
        $amount = $this->currentPayment->amount;
        $destination = $this->currentPayment->to_address;
        $network = $this->currentPayment->network;

        $sdk = $this->getHorizonClient($network);

        ///////////////////////////////////////////////////////
        $responseFeeStats = $sdk->requestFeeStats();
        //$feeCharged = $response->getFeeCharged();
        $feeCharged = $responseFeeStats->getLastLedgerBaseFee();
        ///////////////////////////////////////////////////////

        $senderKeyPair = KeyPair::fromSeed($this->escrowSeed);

        // Load sender account data from the stellar network.
        $sender = $sdk->requestAccount($senderKeyPair->getAccountId());

        /*$minTime = 1641803321;
        $maxTime = 1741803321;
        $timeBounds = new TimeBounds((new \DateTime)->setTimestamp($minTime), (new \DateTime)->setTimestamp($maxTime));*/

        $paymentOperation = (new PaymentOperationBuilder($destination,Asset::native(), $amount))->build();
        $transaction = (new TransactionBuilder($sender))
            ->addOperation($paymentOperation)
            ->setMaxOperationFee($feeCharged)
            ->addMemo(Memo::text($this->currentPayment->identifier))
            //->setTimeBounds($timeBounds)
            ->build();
        // Sign and submit the transaction
        $transaction->sign($senderKeyPair, new Network($network));
        $response = $sdk->submitTransaction($transaction);

        if (!$response->isSuccessful()) {
            //throw new \Exception('Transaction submission failed: ' . json_encode($response->getExtras()));
            return [
                'status' => false,
                'message' => json_encode($response->getExtras())
            ];
        }

        return $response->getHash();
    }

    public function completePayment($paymentId, $txid)
    {
        try {
            $rep = $this->httpClient->request('POST', '/v2/payments/'.$paymentId.'/complete', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Key '.$this->api_key
                ],
                'json' => ['txid' => $txid],
            ]);
            $body = $rep->getBody();
            $body_obj = json_decode($body, false, 512, JSON_UNESCAPED_UNICODE);
            return $body_obj;
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function cancelPayment($paymentId)
    {
        try {
            $rep = $this->httpClient->request('POST', '/v2/payments/'.$paymentId.'/cancel', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Key '.$this->api_key
                ],
            ]);
            $body = $rep->getBody();
            $body_obj = json_decode($body, false, 512, JSON_UNESCAPED_UNICODE);
            return $body_obj;
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function incompletePayments(): Array
    {
        $rep = $this->httpClient->request('GET', '/v2/payments/incomplete_server_payments', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Key '.$this->api_key
            ],
        ]);
        $body = $rep->getBody();
        $body_obj = json_decode($body, false, 512, JSON_UNESCAPED_UNICODE);
        return $body_obj->incomplete_server_payments;
    }

    public function cancelAllIncompletePayments()
    {
        try {
            $incompletePayments = $this->incompletePayments();
            if (is_array($incompletePayments)) {
                foreach ($incompletePayments as $key => $value) {
                    $this->cancelPayment($value->identifier);
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // Steam payment and carryout operations when the minimum amount and above is received 
    public function steamPayment($minAmount=1)
    {
        $owner = $this->getOwner();        
        // create a child process that listens to payment steam
        $pid = pcntl_fork();
        
        if ($pid == 0) {
            // Subscribe to listen for payments for owner account.
            // If we set the cursor to "now" it will not receive old events such as the create account operation.
            $this->sdk->payments()->forAccount($owner)->cursor("now")->stream(function(OperationResponse $payment) {
                $this->logComment(printf('Payment operation %s id %s' . PHP_EOL, get_class($payment), $payment->getOperationId()));
                // exit as soon as our specific payment has been received
                if ($payment instanceof PaymentOperationResponse && floatval($payment->getAmount()) >= $minAmount) {
                    $spendAmount = floatval($payment->getAmount());
                    if(!is_null($this->spend($spendAmount))) exit(1);
                }
            });
        }
        // wait for child process to finish.
        while (pcntl_waitpid(0, $status) != -1) {
            $status = pcntl_wexitstatus($status);
            echo "Completed with status: $status \n";
        }
    }

    // Log activities to storage
    public function logComment($comment)
    {
        echo $comment;
    }

    public function spend($amount){
        $owner = $this->sdk->requestAccount($this->getOwner());
        $feeCharged = floatval($this->getFeeRate());
        $spendAmount = floatval($amount - $feeCharged);
        $paymentOperation = (new PaymentOperationBuilder($this->getDestination(), Asset::native(), $spendAmount))->build();

        $transaction = (new TransactionBuilder($owner))
                ->addOperation($paymentOperation)
                ->setMaxOperationFee($feeCharged)
                ->addMemo(Memo::text("OK"))
                ->build();

        $transaction->sign($this->escrowSeed, new Network($this->network));

        $response = $this->sdk->submitTransaction($transaction);
        if ($response->isSuccessful()) {
            $this->logComment(printf(PHP_EOL."%s Sent %s to %s", $this->getOwner(),$amount,$this->destination));
            return $response->getHash();
        }
        return null;
    }

    // Set a timelock operation transaction

    // read,add & update database data

    // get claimable balance
    public function getClaimable()
    {
        $requestBuilder = $this->sdk->claimableBalances()->forClaimant($this->getOwner());
        $response = $requestBuilder->execute();
        $items = $response->getClaimableBalances()->count();

        if ($items>=1) {
            return $response->getClaimableBalances()->toArray()[0];
        }
        return null;
    
    }

    // claim and spend claimable balance operation  (op_15)
    public function spendClaimable(){
        $cb = $this->getClaimable();
        if (!is_null($cb)) {
            $spender = $this->sdk->requestAccount($this->getOwner());
            $feeCharged = floatval($this->getFeeRate());
            $spendAmount = floatval($cb->getAmount()-$feeCharged);
            $claim = (new ClaimClaimableBalanceOperationBuilder($cb->getBalanceId()))->build();
            $pay = (new PaymentOperationBuilder($this->destination, Asset::native(), $spendAmount))->build();
                
            $transaction = (new TransactionBuilder($spender))
                    ->addOperation($claim)
                    ->addOperation($pay)
                    ->setMaxOperationFee($feeCharged)
                    ->addMemo(Memo::text("OK"))
                    ->build();

            $transaction->sign($this->escrowSeed, new Network($this->network));
            $response = $this->sdk->submitTransaction($transaction);

            if ($response->isSuccessful()) {
                return $response->getHash();
            }
        }
        return null;
    }

}

?>
