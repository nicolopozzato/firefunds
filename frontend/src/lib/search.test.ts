import { describe, expect, it } from 'vitest'
import { highlightParts, matchExcerpt, searchTerms } from './search'

describe('searchTerms', () => {
  it('reads words, phrases and exclusions as the backend does', () => {
    expect(searchTerms('pizza "da Mario" -pasta pizza')).toEqual(['pizza', 'da Mario'])
  })

  it('keeps a word with a slash whole', () => {
    expect(searchTerms('rata 1/3')).toEqual(['rata', '1/3'])
  })
})

describe('highlightParts', () => {
  it('matches ignoring case and accents, on the original text', () => {
    expect(highlightParts('Caffè al Bar', ['caffe'])).toEqual([
      { text: 'Caffè', match: true },
      { text: ' al Bar', match: false },
    ])
  })

  it('merges overlapping matches', () => {
    expect(highlightParts('abcdef', ['abc', 'cde'])).toEqual([
      { text: 'abcde', match: true },
      { text: 'f', match: false },
    ])
  })
})

describe('matchExcerpt', () => {
  it('cuts the text around the first match', () => {
    const notes = `${'x'.repeat(60)} bonifico ${'y'.repeat(60)}`
    expect(matchExcerpt(notes, ['bonifico'], 5)).toBe('… xxxx bonifico yyyy …')
  })

  it('is null without a match', () => {
    expect(matchExcerpt('nothing here', ['bonifico'])).toBeNull()
  })
})
