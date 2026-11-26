import type { Messages } from '@locale/en'

export const LOCALES = ["en","bg"] as const
export type Locale = (typeof LOCALES)[number]
export const DEFAULT_LOCALE: Locale = 'en'

export const isLocale = (v: unknown): v is Locale =>
  typeof v === 'string' && (LOCALES as readonly string[]).includes(v)

export const loaders: Record<Locale, () => Promise<Messages>> = {
  en: () => import('@locale/en').then(m => m.default),
  bg: () => import('@locale/bg').then(m => m.default),
}
