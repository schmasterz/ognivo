export function interpolate(text: string, params?: Record<string, string | number>): string {
  if (!params) return text;
  // longest keys first so `:countries` is replaced before `:count`
  for (const k of Object.keys(params).sort((a, b) => b.length - a.length)) {
    text = text.split(`:${k}`).join(String(params[k]));
  }
  return text;
}
