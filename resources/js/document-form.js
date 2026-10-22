/**
 * Live totals for the document form. This is a preview only: the server recomputes everything
 * with brick/money and stores the authoritative figures. All arithmetic uses BigInt (tiyin),
 * so there is no floating point anywhere near money.
 */

const pad = (value, length) => String(value).padStart(length, '0');

/** "1 500 000,50" -> 150000050n (tiyin), or null if the text is not a valid amount. */
const parseMinor = (text) => {
    const clean = String(text ?? '').replace(/[\s  ]/g, '').replace(',', '.');

    if (!/^\d{1,15}(\.\d{1,2})?$/.test(clean)) return null;

    const [whole, fraction = ''] = clean.split('.');

    return BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
};

/** "2,5" -> 2500n (thousandths), or null. */
const parseMilli = (text) => {
    const clean = String(text ?? '').trim().replace(',', '.');

    if (!/^\d{1,11}(\.\d{1,3})?$/.test(clean)) return null;

    const [whole, fraction = ''] = clean.split('.');

    return BigInt(whole) * 1000n + BigInt((fraction + '000').slice(0, 3));
};

/** "12" or "12,5" percent -> basis points as BigInt. */
const parseBasisPoints = (text) => {
    const clean = String(text ?? '').trim().replace(',', '.');

    if (clean === '') return 0n;
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(clean)) return null;

    const [whole, fraction = ''] = clean.split('.');

    return BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
};

/** Integer division rounding half up (inputs are non-negative). */
const divRound = (numerator, denominator) => (numerator * 2n + denominator) / (denominator * 2n);

const format = (minor, currency) => {
    const whole = minor / 100n;
    const fraction = minor % 100n;
    const grouped = whole.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return `${grouped}${fraction === 0n ? '' : ',' + pad(fraction, 2)} ${currency}`;
};

export function registerDocumentForm(Alpine) {
    Alpine.data('documentForm', ({ lines, currency }) => ({
        lines: lines.map((line) => ({ ...line })),
        currency,

        add() {
            const last = this.lines[this.lines.length - 1];

            this.lines.push({ name: '', unit: '', quantity: '1', unit_price: '', vat_percent: last?.vat_percent ?? '0' });
        },

        remove(index) {
            if (this.lines.length > 1) this.lines.splice(index, 1);
        },

        compute(line) {
            const milli = parseMilli(line.quantity);
            const price = parseMinor(line.unit_price);
            const bp = parseBasisPoints(line.vat_percent);

            if (milli === null || price === null || bp === null) return { subtotal: 0n, vat: 0n, total: 0n };

            const subtotal = divRound(price * milli, 1000n);
            const vat = divRound(subtotal * bp, 10000n);

            return { subtotal, vat, total: subtotal + vat };
        },

        sum(key) {
            return this.lines.reduce((carry, line) => carry + this.compute(line)[key], 0n);
        },

        money(minor) {
            return format(minor, this.currency);
        },

        lineTotal(line) {
            return this.money(this.compute(line).total);
        },

        get subtotal() {
            return this.money(this.sum('subtotal'));
        },

        get vat() {
            return this.money(this.sum('vat'));
        },

        get total() {
            return this.money(this.sum('total'));
        },
    }));
}
