/** In a record's history (HistoryCard): null, nothing or '' shows as "empty" unless a field says otherwise. */
export function isEmptyValue(value: unknown): value is null | undefined | '' {
  return value === null || value === undefined || value === ''
}
