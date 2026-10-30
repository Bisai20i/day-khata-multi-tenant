/**
 * True when an AD `YYYY-MM-DD` date falls inside the closed fiscal year that
 * is currently reopened for correction (`{ start_date, end_date }` as plain
 * Y-m-d strings from the controller), i.e. when the manufacturing posting
 * needs a correction reason. Zero-padded ISO dates compare exactly as
 * strings. A UI hint only: the server resolves the year from the date and
 * refuses a reopened-year posting without a reason either way.
 */
export function isInCorrectionYear(date, year) {
    if (!year || !date) return false;

    const day = String(date).slice(0, 10);

    return day >= year.start_date && day <= year.end_date;
}
