<?php

use Carbon\Carbon;

require 'vendor/autoload.php';
class CommissionCalculator
{
    const WITHDRAWAL_FREE_AMOUNT_EUR_LIMIT = 1000;
    const WITHDRAWAL_FREE_QTY_LIMIT = 3;
    const DEPOSIT_FEE_RATE = 0.0003; // 0.03% for deposits
    const WITHDRAWAL_PRIVATE_FEE_RATE = 0.003; // 0.3% for private withdrawals
    const WITHDRAWAL_BUSINESS_FEE_RATE = 0.005; //0.5% for business withdrawals
    const OPERATION_TYPE_WITHDRAWAL = 'withdraw';
    const OPERATION_TYPE_DEPOSIT = 'deposit';
    const CUSTOMER_GROUP_PRIVATE = 'private';
    const CUSTOMER_GROUP_BUSINESS = 'business';

    /**
     * @throws Exception
     */
    public function calculateCommission(Operation $operation, User $user): float
    {
        $commissionRate = $this->getCommissionRate($operation,$user);

        $commission = $operation->getAmount() * $commissionRate;

        $weekStartDate = $operation->getDate()->copy()->startOfWeek();

        $weekEndDate = $operation->getDate()->copy()->endOfWeek();

        $withdrawalsThisWeek = $user->getWithdrawalsThisWeek($weekStartDate, $weekEndDate);

        //echo var_dump($withdrawalsThisWeek);
        //echo var_dump($withdrawalsThisWeek == 1);
        //echo var_dump($operation->getType() == self::OPERATION_TYPE_WITHDRAWAL);
        //echo var_dump($operation->getUserType() === self::CUSTOMER_GROUP_PRIVATE);
        //die();

        //Calculate in the correct way fee for the first withdrawal who exceed the limit
        if(
            ($withdrawalsThisWeek == 1) &&
            ($operation->getType() == self::OPERATION_TYPE_WITHDRAWAL) &&
            ($operation->getUserType() === self::CUSTOMER_GROUP_PRIVATE)
        )
        {
            echo 'yes';
            $commission = ( $operation->getAmount() - self::WITHDRAWAL_FREE_AMOUNT_EUR_LIMIT ) * $commissionRate;
        }

        echo var_dump($commission);



        return $this->roundUpToCurrencyDecimalPlaces($commission, $operation->getCurrency());
    }

    /**
     * @throws Exception
     */
    private function getCommissionRate(Operation $operation, User $user): float
    {
        if ($operation->getType() === 'deposit') {
            return self::DEPOSIT_FEE_RATE; // 0.03% for deposits
        }

        if ($operation->getUserType() === 'private') {
            return $this->getPrivateWithdrawCommissionRate($operation,$user);
        }

        if ($operation->getUserType() === 'business') {
            return self::WITHDRAWAL_BUSINESS_FEE_RATE; // 0.5% for business withdrawals
        }

        throw new Exception('Invalid operation type or user type');
    }

    /**
     * @throws Exception
     */
    private function getPrivateWithdrawCommissionRate(Operation $operation, User $user): float
    {
        if ($this->isWithdrawalFree($operation, $user)) {
            if ($operation->getAmount() <= self::WITHDRAWAL_FREE_QTY_LIMIT) {
                return 0; // Free of charge
            }
            return self::WITHDRAWAL_PRIVATE_FEE_RATE; // 0.3% for the first 3 withdrawals within the free limit
        }

        return self::WITHDRAWAL_PRIVATE_FEE_RATE; // 0.3% for the 4th and subsequent withdrawals within the free limit
    }

    private function isWithdrawalFree(Operation $operation, User $user): bool
    {
        $weekStartDate = $operation->getDate()->copy()->startOfWeek();

        $weekEndDate = $operation->getDate()->copy()->endOfWeek();

        $withdrawalsThisWeek = $user->getWithdrawalsThisWeek($weekStartDate, $weekEndDate);

        //echo var_dump($withdrawalsThisWeek);
        //die();

        if ($withdrawalsThisWeek < 3) {

            if ($operation->getAmount() <= self::WITHDRAWAL_FREE_AMOUNT_EUR_LIMIT) {
                return true; // Free of charge
            }

            return false; // 0.3% for the first 3 withdrawals within the free limit
        }

        return false; // 0.3% for the 4th and subsequent withdrawals within the free limit
    }

    /**
     * @throws Exception
     */
    private function roundUpToCurrencyDecimalPlaces(float $amount, string $currency): float
    {
        $decimalPlaces = $this->getCurrencyDecimalPlaces($currency);
        $multiplier = 10 ** $decimalPlaces;

        return ceil($amount * $multiplier) / $multiplier;
    }

    /**
     * @throws Exception
     */
    private function getCurrencyDecimalPlaces(string $currency): int
    {
        // Define the decimal places for each currency
        $decimalPlaces = [
            'EUR' => 2, // Euro - 2 decimal places
            'USD' => 2, // US Dollar - 2 decimal places
            'JPY' => 0, // Japanese Yen - 0 decimal places
            // Add more currencies and their decimal places if needed
        ];

        if (isset($decimalPlaces[$currency])) {
            return $decimalPlaces[$currency];
        }

        throw new Exception('Invalid currency');
    }
}

class CurrencyConverter
{
    /**
     * @throws Exception
     */
    public function getExchangeRate(string $baseCurrency, string $targetCurrency): float
    {
        $url = 'https://developers.paysera.com/tasks/api/currency-exchange-rates';
        $response = file_get_contents($url);

        if ($response === false) {
            throw new Exception('Failed to fetch exchange rates.');
        }

        $exchangeRates = json_decode($response, true);

        if (!isset($exchangeRates[$baseCurrency]) || !isset($exchangeRates[$baseCurrency][$targetCurrency])) {
            throw new Exception('Invalid exchange rate data.');
        }

        return $exchangeRates[$baseCurrency][$targetCurrency];
    }

    public function convert(mixed $amount, float $rate)
    {
    }
}


class Operation
{
    private Carbon $date;
    private int $userId;
    private string $userType;
    private string $type;
    private float $amount;
    private string $currency;

    public function __construct(Carbon $date, int $userId, string $userType, string $type, float $amount, string $currency)
    {
        $this->date = $date;
        $this->userId = $userId;
        $this->userType = $userType;
        $this->type = $type;
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function getDate(): Carbon
    {
        return $this->date;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}

class User
{
    private int $id;
    private array $withdrawals;
    private string $user_type;

    public function __construct(int $id, string $user_type)
    {
        $this->id = $id;
        $this->withdrawals = [];
        $this->user_type = $user_type;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId($id): int
    {
        return $this->id = $id;
    }

    public function getUserType(): string
    {
        return $this->user_type;
    }

    public function setUserType($user_type): string
    {
        return $this->user_type = $user_type;
    }

    public function addWithdrawal(Operation $withdrawal): void
    {
        $this->withdrawals[] = $withdrawal;
    }

    public function getWithdrawalsThisWeek(Carbon $weekStartDate, Carbon $weekEndDate): int
    {
        $withdrawalsThisWeek = 0;

        foreach ($this->withdrawals as $withdrawal) {
            if ($withdrawal->getDate()->between($weekStartDate, $weekEndDate)) {
                $withdrawalsThisWeek++;
            }
        }

        return $withdrawalsThisWeek;
    }
}

// Usage example
$csvFile = $argv[1];
$csvData = file_get_contents($csvFile);
$lines = explode("\n", $csvData);
//array_shift($lines); // Remove header line

$commissionCalculator = new CommissionCalculator();
$currencyConverter = new CurrencyConverter();
$users = [];

foreach ($lines as $line) {
    $data = str_getcsv($line);

    // Extract operation data from CSV
    $date = Carbon::createFromFormat('Y-m-d', $data[0]);
    $userId = $data[1];
    $userType = $data[2];
    $userInstance = new User($userId,$userType);
    $operationType = $data[3];
    $amount = $data[4];
    $currency = $data[5];

    //echo var_dump($amount);die();

    // Convert amount to EUR if needed
    if ($currency !== 'EUR') {
        try {
            $rate = $currencyConverter->getExchangeRate($currency, 'EUR');
        } catch (Exception $e) {
        }
        //$amount = $currencyConverter->convert($amount, $rate);
    }

    // Create User if not already created
    if (!isset($users[$userId])) {
        $users[$userId] = new User($userId,$userType);
    }

    // Create Operation object
    $operation = new Operation($date, $userId, $userType, $operationType, $amount, $currency);

    // Add withdrawal to the User if applicable
    if ($operation->getType() === 'withdraw') {
        $users[$userId]->addWithdrawal($operation);
    }

    //echo var_dump($users[$userId]);die();

    // Calculate commission fee
    try {
        $commission = $commissionCalculator->calculateCommission($operation, $users[$userId]);
    } catch (Exception $e) {
        throw new Exception('Something went wrong when trying to calculate commission');
    }

    // Output the commission fee
    echo $userId.' - '.$userType.' - '.$operationType.' - '.$amount.' - '.$currency.' => '.$commission . "\n";
}
