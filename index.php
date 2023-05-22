<?php

require 'vendor/autoload.php';

use Carbon\Carbon;

class CommissionCalculator
{
    public function calculateCommission(Operation $operation): float
    {
        $commissionRate = $this->getCommissionRate($operation);
        $commission = $operation->getAmount() * $commissionRate;
        $commission = $this->roundUpToCurrencyDecimalPlaces($commission, $operation->getCurrency());
        return $commission;
    }

    private function getCommissionRate(Operation $operation): float
    {
        if ($operation->getType() === 'deposit') {
            return 0.0003; // 0.03% for deposits
        }

        if ($operation->getUserType() === 'private') {
            return $this->getPrivateWithdrawCommissionRate($operation);
        }

        if ($operation->getUserType() === 'business') {
            return 0.005; // 0.5% for business withdrawals
        }

        throw new Exception('Invalid operation type or user type');
    }

    private function getPrivateWithdrawCommissionRate(Operation $operation): float
    {
        $weekStartDate = $operation->getDate()->copy()->startOfWeek();
        $weekEndDate = $operation->getDate()->copy()->endOfWeek();

        $withdrawalsThisWeek = $operation->getUser()->getWithdrawalsThisWeek($weekStartDate, $weekEndDate);

        if ($withdrawalsThisWeek < 3) {
            if ($operation->getAmount() <= 1000) {
                return 0; // Free of charge
            }
            return 0.003; // 0.3% for the first 3 withdrawals within the free limit
        }

        return 0.003; // 0.3% for the 4th and subsequent withdrawals within the free limit
    }

    private function roundUpToCurrencyDecimalPlaces(float $amount, string $currency): float
    {
        // Perform rounding based on currency decimal places
        // Example: 0.023 EUR should be rounded up to 0.03 EUR

        // Implement rounding logic here
        // ...

        return $amount; // Placeholder return value
    }
}

class CurrencyConverter
{
    public function getExchangeRate(string $fromCurrency, string $toCurrency): float
    {
        // Retrieve exchange rate from API or other sources
        // Example: EUR:USD - 1:1.1497, EUR:JPY - 1:129.53

        // Implement exchange rate retrieval logic here
        // ...

        return 1; // Placeholder return value
    }

    public function convert(float $amount, float $exchangeRate): float
    {
        return $amount * $exchangeRate;
    }
}

class Operation
{
    private $date;
    private $userId;
    private $userType;
    private $type;
    private $amount;
    private $currency;

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
    private $id;
    private $withdrawals;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->withdrawals = [];
    }

    public function getId(): int
    {
        return $this->id;
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
array_shift($lines); // Remove header line

$commissionCalculator = new CommissionCalculator();
$currencyConverter = new CurrencyConverter();
$users = [];

foreach ($lines as $line) {
    $data = str_getcsv($line);

    // Extract operation data from CSV
    $date = Carbon::createFromFormat('Y-m-d', $data[0]);
    $userId = $data[1];
    $userType = $data[2];
    $operationType = $data[3];
    $amount = $data[4];
    $currency = $data[5];

    // Convert amount to EUR if needed
    if ($currency !== 'EUR') {
        $rate = $currencyConverter->getExchangeRate($currency, 'EUR');
        $amount = $currencyConverter->convert($amount, $rate);
    }

    // Create User if not already created
    if (!isset($users[$userId])) {
        $users[$userId] = new User($userId);
    }

    // Create Operation object
    $operation = new Operation($date, $userId, $userType, $operationType, $amount, $currency);

    // Add withdrawal to the User if applicable
    if ($operation->getType() === 'withdraw') {
        $users[$userId]->addWithdrawal($operation);
    }

    // Calculate commission fee
    $commission = $commissionCalculator->calculateCommission($operation);

    // Output the commission fee
    echo $commission . "\n";
}
