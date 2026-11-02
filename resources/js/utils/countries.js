/**
 * Country options (ISO 3166-1 alpha-2) with names localized by the browser.
 * Italy first, then the most common countries for Italian businesses.
 */
const CODES = [
    'IT',
    'SM',
    'VA',
    'CH',
    'AT',
    'BE',
    'BG',
    'CY',
    'HR',
    'DK',
    'EE',
    'FI',
    'FR',
    'DE',
    'GR',
    'IE',
    'LV',
    'LT',
    'LU',
    'MT',
    'NL',
    'PL',
    'PT',
    'CZ',
    'RO',
    'SK',
    'SI',
    'ES',
    'SE',
    'HU',
    'NO',
    'GB',
    'AL',
    'RS',
    'TR',
    'US',
    'CA',
    'BR',
    'AR',
    'CN',
    'JP',
    'IN',
    'AE',
    'MA',
    'TN',
    'EG',
    'ZA',
    'AU',
];

const names = new Intl.DisplayNames([document.documentElement.lang || 'it'], { type: 'region' });

export const countries = CODES.map((code) => ({ value: code, title: names.of(code) ?? code }));

export function countryName(code) {
    return code ? (names.of(code) ?? code) : '';
}
