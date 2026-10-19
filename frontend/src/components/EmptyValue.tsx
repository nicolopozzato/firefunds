/**
 * What a cell or field shows when there is no value: a light dash, visible
 * enough to tell the value is missing, never as loud as real data.
 */
export default function EmptyValue() {
  return <span className="text-muted-foreground/50">—</span>
}
