import { highlightParts } from '@/lib/search'

/** text with the parts matching a search term marked; plain text when there are no terms. */
export default function Highlight({ text, terms }: { text: string; terms?: string[] }) {
  if (!terms || terms.length === 0) return <>{text}</>

  return (
    <>
      {highlightParts(text, terms).map((part, index) =>
        part.match ? (
          <mark key={index} className="rounded-sm bg-yellow-200 px-0.5 text-inherit dark:bg-yellow-500/30">
            {part.text}
          </mark>
        ) : (
          part.text
        )
      )}
    </>
  )
}
