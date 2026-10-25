<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Currencies become a fixed reference list instead of something an
 * administrator manages: every active ISO 4217 currency is there (code, English
 * name, symbol, minor units; the app translates names with Intl), none can be
 * disabled or deleted. Which ones matter is decided by the accounts,
 * instruments and preferences that use them.
 */
final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Currencies: a fixed ISO 4217 list, no enabling or deleting';
    }

    public function up(Schema $schema): void
    {
        // Soft-deleted rows of an admin-managed list nobody uses: gone with the feature.
        $this->addSql('DELETE FROM currency c WHERE c.deleted_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM account a WHERE a.currency_id = c.id)');
        $this->addSql('DROP INDEX uniq_currency_code_active');
        $this->addSql('ALTER TABLE currency DROP is_enabled');
        $this->addSql('ALTER TABLE currency DROP deleted_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_currency_code ON currency (code)');

        foreach (self::CURRENCIES as [$code, $name, $symbol, $decimals]) {
            $this->addSql(
                'INSERT INTO currency (id, code, name, symbol, decimal_places, created_at, updated_at) '
                .'SELECT gen_random_uuid(), ?, ?, ?, ?, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM currency WHERE code = ?)',
                [$code, $name, $symbol, $decimals, $code],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The currency list is not taken back.');
    }

    /** @var list<array{string, string, string, int}> */
    private const CURRENCIES = [
            ['AED', 'United Arab Emirates Dirham', 'AED', 2],
            ['AFN', 'Afghan Afghani', 'AFN', 2],
            ['ALL', 'Albanian Lek', 'ALL', 2],
            ['AMD', 'Armenian Dram', 'AMD', 2],
            ['ANG', 'Netherlands Antillean Guilder', 'ANG', 2],
            ['AOA', 'Angolan Kwanza', 'AOA', 2],
            ['ARS', 'Argentine Peso', 'ARS', 2],
            ['AUD', 'Australian Dollar', 'A$', 2],
            ['AWG', 'Aruban Florin', 'AWG', 2],
            ['AZN', 'Azerbaijani Manat', 'AZN', 2],
            ['BAM', 'Bosnia-Herzegovina Convertible Mark', 'BAM', 2],
            ['BBD', 'Barbadian Dollar', 'BBD', 2],
            ['BDT', 'Bangladeshi Taka', 'BDT', 2],
            ['BGN', 'Bulgarian Lev', 'BGN', 2],
            ['BHD', 'Bahraini Dinar', 'BHD', 3],
            ['BIF', 'Burundian Franc', 'BIF', 0],
            ['BMD', 'Bermudan Dollar', 'BMD', 2],
            ['BND', 'Brunei Dollar', 'BND', 2],
            ['BOB', 'Bolivian Boliviano', 'BOB', 2],
            ['BRL', 'Brazilian Real', 'R$', 2],
            ['BSD', 'Bahamian Dollar', 'BSD', 2],
            ['BTN', 'Bhutanese Ngultrum', 'BTN', 2],
            ['BWP', 'Botswanan Pula', 'BWP', 2],
            ['BYN', 'Belarusian Ruble', 'BYN', 2],
            ['BZD', 'Belize Dollar', 'BZD', 2],
            ['CAD', 'Canadian Dollar', 'CA$', 2],
            ['CDF', 'Congolese Franc', 'CDF', 2],
            ['CHF', 'Swiss Franc', 'CHF', 2],
            ['CLP', 'Chilean Peso', 'CLP', 0],
            ['CNY', 'Chinese Yuan', 'CN¥', 2],
            ['COP', 'Colombian Peso', 'COP', 2],
            ['CRC', 'Costa Rican Colón', 'CRC', 2],
            ['CUP', 'Cuban Peso', 'CUP', 2],
            ['CVE', 'Cape Verdean Escudo', 'CVE', 2],
            ['CZK', 'Czech Koruna', 'CZK', 2],
            ['DJF', 'Djiboutian Franc', 'DJF', 0],
            ['DKK', 'Danish Krone', 'DKK', 2],
            ['DOP', 'Dominican Peso', 'DOP', 2],
            ['DZD', 'Algerian Dinar', 'DZD', 2],
            ['EGP', 'Egyptian Pound', 'EGP', 2],
            ['ERN', 'Eritrean Nakfa', 'ERN', 2],
            ['ETB', 'Ethiopian Birr', 'ETB', 2],
            ['EUR', 'Euro', '€', 2],
            ['FJD', 'Fijian Dollar', 'FJD', 2],
            ['FKP', 'Falkland Islands Pound', 'FKP', 2],
            ['GBP', 'British Pound', '£', 2],
            ['GEL', 'Georgian Lari', 'GEL', 2],
            ['GHS', 'Ghanaian Cedi', 'GHS', 2],
            ['GIP', 'Gibraltar Pound', 'GIP', 2],
            ['GMD', 'Gambian Dalasi', 'GMD', 2],
            ['GNF', 'Guinean Franc', 'GNF', 0],
            ['GTQ', 'Guatemalan Quetzal', 'GTQ', 2],
            ['GYD', 'Guyanaese Dollar', 'GYD', 2],
            ['HKD', 'Hong Kong Dollar', 'HK$', 2],
            ['HNL', 'Honduran Lempira', 'HNL', 2],
            ['HTG', 'Haitian Gourde', 'HTG', 2],
            ['HUF', 'Hungarian Forint', 'HUF', 2],
            ['IDR', 'Indonesian Rupiah', 'IDR', 2],
            ['ILS', 'Israeli New Shekel', '₪', 2],
            ['INR', 'Indian Rupee', '₹', 2],
            ['IQD', 'Iraqi Dinar', 'IQD', 3],
            ['IRR', 'Iranian Rial', 'IRR', 2],
            ['ISK', 'Icelandic Króna', 'ISK', 0],
            ['JMD', 'Jamaican Dollar', 'JMD', 2],
            ['JOD', 'Jordanian Dinar', 'JOD', 3],
            ['JPY', 'Japanese Yen', '¥', 0],
            ['KES', 'Kenyan Shilling', 'KES', 2],
            ['KGS', 'Kyrgystani Som', 'KGS', 2],
            ['KHR', 'Cambodian Riel', 'KHR', 2],
            ['KMF', 'Comorian Franc', 'KMF', 0],
            ['KPW', 'North Korean Won', 'KPW', 2],
            ['KRW', 'South Korean Won', '₩', 0],
            ['KWD', 'Kuwaiti Dinar', 'KWD', 3],
            ['KYD', 'Cayman Islands Dollar', 'KYD', 2],
            ['KZT', 'Kazakhstani Tenge', 'KZT', 2],
            ['LAK', 'Laotian Kip', 'LAK', 2],
            ['LBP', 'Lebanese Pound', 'LBP', 2],
            ['LKR', 'Sri Lankan Rupee', 'LKR', 2],
            ['LRD', 'Liberian Dollar', 'LRD', 2],
            ['LSL', 'Lesotho Loti', 'LSL', 2],
            ['LYD', 'Libyan Dinar', 'LYD', 3],
            ['MAD', 'Moroccan Dirham', 'MAD', 2],
            ['MDL', 'Moldovan Leu', 'MDL', 2],
            ['MGA', 'Malagasy Ariary', 'MGA', 2],
            ['MKD', 'Macedonian Denar', 'MKD', 2],
            ['MMK', 'Myanmar Kyat', 'MMK', 2],
            ['MNT', 'Mongolian Tugrik', 'MNT', 2],
            ['MOP', 'Macanese Pataca', 'MOP', 2],
            ['MRU', 'Mauritanian Ouguiya', 'MRU', 2],
            ['MUR', 'Mauritian Rupee', 'MUR', 2],
            ['MVR', 'Maldivian Rufiyaa', 'MVR', 2],
            ['MWK', 'Malawian Kwacha', 'MWK', 2],
            ['MXN', 'Mexican Peso', 'MX$', 2],
            ['MYR', 'Malaysian Ringgit', 'MYR', 2],
            ['MZN', 'Mozambican Metical', 'MZN', 2],
            ['NAD', 'Namibian Dollar', 'NAD', 2],
            ['NGN', 'Nigerian Naira', 'NGN', 2],
            ['NIO', 'Nicaraguan Córdoba', 'NIO', 2],
            ['NOK', 'Norwegian Krone', 'NOK', 2],
            ['NPR', 'Nepalese Rupee', 'NPR', 2],
            ['NZD', 'New Zealand Dollar', 'NZ$', 2],
            ['OMR', 'Omani Rial', 'OMR', 3],
            ['PAB', 'Panamanian Balboa', 'PAB', 2],
            ['PEN', 'Peruvian Sol', 'PEN', 2],
            ['PGK', 'Papua New Guinean Kina', 'PGK', 2],
            ['PHP', 'Philippine Peso', '₱', 2],
            ['PKR', 'Pakistani Rupee', 'PKR', 2],
            ['PLN', 'Polish Zloty', 'PLN', 2],
            ['PYG', 'Paraguayan Guarani', 'PYG', 0],
            ['QAR', 'Qatari Riyal', 'QAR', 2],
            ['RON', 'Romanian Leu', 'RON', 2],
            ['RSD', 'Serbian Dinar', 'RSD', 2],
            ['RUB', 'Russian Ruble', 'RUB', 2],
            ['RWF', 'Rwandan Franc', 'RWF', 0],
            ['SAR', 'Saudi Riyal', 'SAR', 2],
            ['SBD', 'Solomon Islands Dollar', 'SBD', 2],
            ['SCR', 'Seychellois Rupee', 'SCR', 2],
            ['SDG', 'Sudanese Pound', 'SDG', 2],
            ['SEK', 'Swedish Krona', 'SEK', 2],
            ['SGD', 'Singapore Dollar', 'SGD', 2],
            ['SHP', 'St. Helena Pound', 'SHP', 2],
            ['SLE', 'Sierra Leonean Leone', 'SLE', 2],
            ['SOS', 'Somali Shilling', 'SOS', 2],
            ['SRD', 'Surinamese Dollar', 'SRD', 2],
            ['SSP', 'South Sudanese Pound', 'SSP', 2],
            ['STN', 'São Tomé & Príncipe Dobra', 'STN', 2],
            ['SVC', 'Salvadoran Colón', 'SVC', 2],
            ['SYP', 'Syrian Pound', 'SYP', 2],
            ['SZL', 'Swazi Lilangeni', 'SZL', 2],
            ['THB', 'Thai Baht', 'THB', 2],
            ['TJS', 'Tajikistani Somoni', 'TJS', 2],
            ['TMT', 'Turkmenistani Manat', 'TMT', 2],
            ['TND', 'Tunisian Dinar', 'TND', 3],
            ['TOP', 'Tongan Paʻanga', 'TOP', 2],
            ['TRY', 'Turkish Lira', 'TRY', 2],
            ['TTD', 'Trinidad & Tobago Dollar', 'TTD', 2],
            ['TWD', 'New Taiwan Dollar', 'NT$', 2],
            ['TZS', 'Tanzanian Shilling', 'TZS', 2],
            ['UAH', 'Ukrainian Hryvnia', 'UAH', 2],
            ['UGX', 'Ugandan Shilling', 'UGX', 0],
            ['USD', 'US Dollar', '$', 2],
            ['UYU', 'Uruguayan Peso', 'UYU', 2],
            ['UZS', 'Uzbekistani Som', 'UZS', 2],
            ['VES', 'Venezuelan Bolívar', 'VES', 2],
            ['VND', 'Vietnamese Dong', '₫', 0],
            ['VUV', 'Vanuatu Vatu', 'VUV', 0],
            ['WST', 'Samoan Tala', 'WST', 2],
            ['XAF', 'Central African CFA Franc', 'FCFA', 0],
            ['XCD', 'East Caribbean Dollar', 'EC$', 2],
            ['XOF', 'West African CFA Franc', 'F CFA', 0],
            ['XPF', 'CFP Franc', 'CFPF', 0],
            ['YER', 'Yemeni Rial', 'YER', 2],
            ['ZAR', 'South African Rand', 'ZAR', 2],
            ['ZMW', 'Zambian Kwacha', 'ZMW', 2],
            ['ZWL', 'Zimbabwean Dollar (2009–2024)', 'ZWL', 2],
    ];
}
