<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'precision' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'precision' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'precision' => 2],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'precision' => 2],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'precision' => 2],
            ['code' => 'QAR', 'name' => 'Qatari Riyal', 'symbol' => 'ر.ق', 'precision' => 2],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'precision' => 3],
            ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'symbol' => '.د.ب', 'precision' => 3],
            ['code' => 'OMR', 'name' => 'Omani Rial', 'symbol' => 'ر.ع.', 'precision' => 3],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'symbol' => 'د.ا', 'precision' => 3],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£', 'precision' => 2],

            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'precision' => 2],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨', 'precision' => 2],
            ['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'precision' => 2],
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'precision' => 2],
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'precision' => 2],

            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'precision' => 2],

            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'precision' => 0],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'precision' => 2],

            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'precision' => 2],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'precision' => 2],

            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'precision' => 2],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'precision' => 2],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'precision' => 2],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                [
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'precision' => $currency['precision'],
                    'is_active' => true,
                ]
            );
        }
    }
}
