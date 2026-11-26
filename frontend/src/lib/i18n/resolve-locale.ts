import { LOCALES, DEFAULT_LOCALE, isLocale, type Locale } from "./loaders.ts";

interface LangWeight {
  tag: string;
  q: number;
}

export function resolveLocale(
  cookieValue: string | undefined,
  acceptLanguage: string | null
): Locale {
  if (isLocale(cookieValue)) {
    return cookieValue;
  }

  if (!acceptLanguage || !acceptLanguage.trim()) {
    return DEFAULT_LOCALE;
  }

  const items: LangWeight[] = [];
  for (const part of acceptLanguage.split(",")) {
    const trimmed = part.trim();
    if (!trimmed) continue;
    const [tagPart, qPart] = trimmed.split(";");
    const tag = tagPart.trim().toLowerCase();
    if (!tag || tag === "*") continue;

    let q = 1;
    if (qPart) {
      const match = /q=([0-9.]+)/i.exec(qPart.trim());
      if (match) {
        const parsed = Number.parseFloat(match[1]);
        if (!Number.isNaN(parsed)) {
          q = parsed;
        }
      }
    }

    if (q > 0) {
      items.push({ tag, q });
    }
  }

  // Sort by weight descending
  items.sort((a, b) => b.q - a.q);

  for (const item of items) {
    // 1. Try exact case-insensitive match against LOCALES (e.g. pt-BR)
    const exact = (LOCALES as readonly string[]).find((l) => l.toLowerCase() === item.tag);
    if (exact && isLocale(exact)) {
      return exact;
    }

    // 2. Try base language code (e.g. "bg" from "bg-BG")
    const baseCode = item.tag.split("-")[0];
    const baseMatch = (LOCALES as readonly string[]).find((l) => l.toLowerCase() === baseCode);
    if (baseMatch && isLocale(baseMatch)) {
      return baseMatch;
    }
  }

  return DEFAULT_LOCALE;
}
