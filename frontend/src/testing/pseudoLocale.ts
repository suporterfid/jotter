export type PseudoLocale = 'en-XA' | 'ar-XB'

const accentMap: Record<string, string> = {
  a: 'à', A: 'À', b: 'ƀ', B: 'Ƀ', c: 'ç', C: 'Ç', d: 'ď', D: 'Ď',
  e: 'ë', E: 'Ë', f: 'ƒ', F: 'Ƒ', g: 'ğ', G: 'Ğ', h: 'ħ', H: 'Ħ',
  i: 'ï', I: 'Ï', j: 'ĵ', J: 'Ĵ', k: 'ķ', K: 'Ķ', l: 'ľ', L: 'Ľ',
  m: 'ɱ', M: 'Ṁ', n: 'ñ', N: 'Ñ', o: 'ô', O: 'Ô', p: 'þ', P: 'Þ',
  q: 'ɋ', Q: 'Ɋ', r: 'ř', R: 'Ř', s: 'š', S: 'Š', t: 'ŧ', T: 'Ŧ',
  u: 'ü', U: 'Ü', v: 'ṽ', V: 'Ṽ', w: 'ŵ', W: 'Ŵ', x: 'ẋ', X: 'Ẋ',
  y: 'ÿ', Y: 'Ÿ', z: 'ž', Z: 'Ž',
}

function preserveInterpolations(message: string, transform: (text: string) => string): string {
  return message
    .split(/(\{[^}]+\})/)
    .map(part => /^\{[^}]+\}$/.test(part) ? part : transform(part))
    .join('')
}

/** Test-only pseudo-localization; it is deliberately not registered in i18n. */
export function pseudoLocalize(message: string, locale: PseudoLocale): string {
  if (locale === 'ar-XB') {
    return `\u202E${preserveInterpolations(message, text => text.split('').reverse().join(''))}\u202C`
  }

  const expanded = preserveInterpolations(message, text => text.replace(/[A-Za-z]/g, character => accentMap[character] ?? character))
  return `［${expanded}${'~'.repeat(Math.ceil(message.length * 0.3))}］`
}
