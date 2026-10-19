/**
 * The search box's query, read as the backend reads it (`App\Core\Search\SearchQuery`):
 * words separated by spaces, a "quoted phrase" kept together, a leading "-"
 * to exclude a word or phrase. Only the included terms are highlighted.
 */
export function searchTerms(query: string): string[] {
  const terms: string[] = []
  for (const match of query.matchAll(/(-?)(?:"([^"]*)"|(\S+))/g)) {
    const [, not, phrase, word] = match
    const term = (phrase ?? word.replace(/"/g, '')).trim()
    if (term === '' || term === '-' || not === '-') continue
    if (!terms.includes(term)) terms.push(term)
  }
  return terms
}

/** Lower case, without accents, as the backend's `unaccent` + ILIKE compare. */
function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
}

/** Where each term occurs in text, as [start, end) ranges of the original string, merged and in order. */
function matchRanges(text: string, terms: string[]): [number, number][] {
  // The folded text, and for each of its characters the original one's [start, end).
  let folded = ''
  const origin: [number, number][] = []
  let offset = 0
  for (const char of text) {
    const foldedChar = fold(char)
    for (let i = 0; i < foldedChar.length; i++) origin.push([offset, offset + char.length])
    folded += foldedChar
    offset += char.length
  }

  const ranges: [number, number][] = []
  for (const term of terms.map(fold)) {
    if (term === '') continue
    for (let at = folded.indexOf(term); at !== -1; at = folded.indexOf(term, at + term.length)) {
      ranges.push([origin[at][0], origin[at + term.length - 1][1]])
    }
  }

  ranges.sort((a, b) => a[0] - b[0])
  const merged: [number, number][] = []
  for (const range of ranges) {
    const last = merged[merged.length - 1]
    if (last && range[0] <= last[1]) last[1] = Math.max(last[1], range[1])
    else merged.push([...range])
  }
  return merged
}

export interface HighlightPart {
  text: string
  match: boolean
}

/** The text split into the parts that match a term and those that don't. */
export function highlightParts(text: string, terms: string[]): HighlightPart[] {
  const parts: HighlightPart[] = []
  let position = 0
  for (const [start, end] of matchRanges(text, terms)) {
    if (start > position) parts.push({ text: text.slice(position, start), match: false })
    parts.push({ text: text.slice(start, end), match: true })
    position = end
  }
  if (position < text.length) parts.push({ text: text.slice(position), match: false })
  return parts
}

export function containsTerm(text: string | null | undefined, terms: string[]): boolean {
  return !!text && matchRanges(text, terms).length > 0
}

/** A short excerpt of text around its first match, on one line, or null when nothing matches. */
export function matchExcerpt(text: string | null | undefined, terms: string[], around = 40): string | null {
  if (!text) return null
  const [first] = matchRanges(text, terms)
  if (!first) return null

  const start = Math.max(0, first[0] - around)
  const end = Math.min(text.length, first[1] + around)
  const excerpt = text.slice(start, end).replace(/\s+/g, ' ').trim()
  return `${start > 0 ? '… ' : ''}${excerpt}${end < text.length ? ' …' : ''}`
}
