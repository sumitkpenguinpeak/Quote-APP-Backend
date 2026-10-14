<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Customer na address fields ne Shopify MailingAddressInput format ma convert kare chhe.
 * Shopify countryCode (ISO-2) ane provinceCode mange chhe, etle "india" / "Gujarat" jevi
 * text values ne code ma map kariye chhie.
 */
class ShopifyAddress
{
    private const COUNTRIES = [
        'india' => 'IN', 'united states' => 'US', 'usa' => 'US', 'united states of america' => 'US',
        'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB', 'canada' => 'CA',
        'australia' => 'AU', 'new zealand' => 'NZ', 'germany' => 'DE', 'france' => 'FR',
        'italy' => 'IT', 'spain' => 'ES', 'netherlands' => 'NL', 'ireland' => 'IE',
        'united arab emirates' => 'AE', 'uae' => 'AE', 'singapore' => 'SG', 'japan' => 'JP',
        'china' => 'CN', 'south africa' => 'ZA', 'brazil' => 'BR', 'mexico' => 'MX',
        'saudi arabia' => 'SA', 'bangladesh' => 'BD', 'nepal' => 'NP', 'sri lanka' => 'LK',
        'pakistan' => 'PK',
    ];

    // Country code => [state name (lowercase) => province code]
    private const PROVINCES = [
        'IN' => [
            'andhra pradesh' => 'AP', 'arunachal pradesh' => 'AR', 'assam' => 'AS', 'bihar' => 'BR',
            'chhattisgarh' => 'CG', 'goa' => 'GA', 'gujarat' => 'GJ', 'haryana' => 'HR',
            'himachal pradesh' => 'HP', 'jharkhand' => 'JH', 'karnataka' => 'KA', 'kerala' => 'KL',
            'madhya pradesh' => 'MP', 'maharashtra' => 'MH', 'manipur' => 'MN', 'meghalaya' => 'ML',
            'mizoram' => 'MZ', 'nagaland' => 'NL', 'odisha' => 'OR', 'punjab' => 'PB',
            'rajasthan' => 'RJ', 'sikkim' => 'SK', 'tamil nadu' => 'TN', 'telangana' => 'TS',
            'tripura' => 'TR', 'uttar pradesh' => 'UP', 'uttarakhand' => 'UK', 'west bengal' => 'WB',
            'delhi' => 'DL', 'jammu and kashmir' => 'JK', 'ladakh' => 'LA', 'chandigarh' => 'CH',
            'puducherry' => 'PY', 'andaman and nicobar islands' => 'AN',
            'dadra and nagar haveli and daman and diu' => 'DH', 'lakshadweep' => 'LD',
        ],
        'US' => [
            'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
            'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'florida' => 'FL', 'georgia' => 'GA',
            'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA',
            'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
            'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
            'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH',
            'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC',
            'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR', 'pennsylvania' => 'PA',
            'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN',
            'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
            'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
        ],
    ];

    /**
     * Shopify MailingAddressInput array return kare. Jo address ma country/address
     * j nathi (ke country resolve nathi thati) to null -> draft order address vagar bane chhe.
     */
    public static function fromCustomer(?Customer $customer): ?array
    {
        if (! $customer || blank($customer->address) || blank($customer->country)) {
            return null;
        }

        $countryCode = self::countryCode($customer->country);
        if (! $countryCode) {
            return null;
        }

        $address = [
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'company' => $customer->company_name,
            'phone' => $customer->phone,
            'address1' => $customer->address,
            'city' => $customer->city,
            'zip' => $customer->zip,
            'countryCode' => $countryCode,
        ];

        if ($province = self::provinceCode($countryCode, $customer->state)) {
            $address['provinceCode'] = $province;
        }

        return array_filter($address, fn ($v) => filled($v));
    }

    /**
     * Storefront form na address array ne Shopify MailingAddressInput ma convert kare chhe
     * (customer record vagar). country_code / state_code hoy to te vapre, nahi to name par thi map kare.
     */
    public static function fromArray(array $a): ?array
    {
        $code = trim((string) ($a['country_code'] ?? ''));
        $countryCode = preg_match('/^[A-Za-z]{2}$/', $code) ? strtoupper($code) : null;

        if (! $countryCode && filled($a['country'] ?? null)) {
            $countryCode = self::countryCode((string) $a['country']);
        }
        if (! $countryCode) {
            return null;
        }

        $address = [
            'address1' => $a['address'] ?? null,
            'city' => $a['city'] ?? null,
            'zip' => $a['zip'] ?? null,
            'countryCode' => $countryCode,
        ];

        $stateCode = trim((string) ($a['state_code'] ?? ''));
        $province = $stateCode !== '' ? strtoupper($stateCode) : self::provinceCode($countryCode, $a['state'] ?? null);
        if ($province) {
            $address['provinceCode'] = $province;
        }

        return array_filter($address, fn ($v) => filled($v));
    }

    private static function countryCode(string $country): ?string
    {
        $country = trim($country);

        if (preg_match('/^[A-Za-z]{2}$/', $country)) {
            return strtoupper($country);
        }

        return self::COUNTRIES[strtolower($country)] ?? null;
    }

    private static function provinceCode(string $countryCode, ?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $state = strtolower(trim($state));
        $map = self::PROVINCES[$countryCode] ?? [];

        // Already a code (e.g. "GJ", "CA")
        if (strlen($state) <= 3) {
            return strtoupper($state);
        }

        return $map[$state] ?? null;
    }
}