/**
 * Canvas i18n. Magento's translation pipeline stays authoritative: the server
 * builds a phrase map from `__()` calls (Model/I18n/PhraseCatalog, injected by
 * Mount.php into the bootstrap config as `i18n`), and the client only LOOKS UP
 * — calling t with an English phrase returns the server's translation, or the
 * phrase unchanged when the map has no row (identity in en_US, in tests, and
 * for any phrase the catalog missed).
 *
 * Contract with dev/tools/i18n-collect-phrases.php: every call site passes ONE
 * static string literal, which is exactly what the collector scans for (and
 * why this comment spells no example call out). Dynamic fragments stay outside
 * the call. PhraseCatalog.php must list every phrase used here so the map is
 * complete; the collector regenerates i18n/en_US.csv from both sides of that
 * seam.
 */

let translations: Record<string, string> = {};

/** Install the server-provided phrase map. Called once at mount. */
export function setTranslations(map: Record<string, string>): void {
  translations = map && typeof map === 'object' ? map : {};
}

/** Translate one phrase; a missing/empty row falls back to the English text. */
export function t(phrase: string): string {
  const translated = translations[phrase];
  return typeof translated === 'string' && translated !== '' ? translated : phrase;
}
