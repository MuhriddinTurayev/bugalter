// Money is integer tiyin (1 so'm = 100 tiyin). Never a float: amounts are parsed from text with string arithmetic.

/**
 * "1 234 567,89" / "1,234,567.89" / "1234567" (so'm) -> tiyin as a safe integer, or null when it is not an amount.
 * More than two decimals, a sign in the middle, or a value beyond Number.MAX_SAFE_INTEGER tiyin is not an amount.
 */
export function parseMinor(text) {
  if (typeof text !== 'string') return null;
  const cleaned = text.replace(/[\s  ]/g, '').replace(/(?:so['ʻ’]?m|сум|uzs)$/i, '');
  const match = /^(-)?(\d{1,3}(?:[,.]\d{3})+|\d+)(?:[,.](\d{1,2}))?$/.exec(cleaned);
  if (!match) return null;

  const [, sign, wholeRaw, fraction = ''] = match;
  // "1,234" with exactly three trailing digits and no decimal part is a thousands group, not a fraction.
  // Thousands groups must use one separator: "12.345,678" is not a number.
  if (/,/.test(wholeRaw) && /\./.test(wholeRaw)) return null;
  const whole = wholeRaw.replace(/[,.]/g, '');
  const tiyin = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0') || '0');
  const value = sign ? -tiyin : tiyin;

  return value >= BigInt(Number.MIN_SAFE_INTEGER) && value <= BigInt(Number.MAX_SAFE_INTEGER) ? Number(value) : null;
}

/** Tiyin -> "1 234 567,89" for messages only. */
export function formatMinor(minor) {
  const negative = minor < 0;
  const abs = BigInt(Math.abs(minor));
  const whole = (abs / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  const fraction = (abs % 100n).toString().padStart(2, '0');
  return `${negative ? '-' : ''}${whole},${fraction}`;
}
