import { api } from './api'

export type RuleKind = 'out' | 'in' | 'ignore'

export interface SourceRule {
  kind: RuleKind
  pattern: string
}

/** A rule on the Sources page: `key` keeps it apart from the others while it is dragged. */
export interface RuleRow extends SourceRule {
  key: string
}

let nextKey = 0

export function ruleRows(rules: SourceRule[]): RuleRow[] {
  return rules.map((rule) => ({ ...rule, key: `rule-${nextKey++}` }))
}

/** An app on the phone whose notifications reach the server. */
export interface NotificationSource {
  id: string
  packageName: string
  appName: string
  accountId: string | null
  rules: SourceRule[]
  total?: number
  unread?: number
  latest?: string | null
}

export interface Reading {
  kind: RuleKind
  amount: string | null
  merchant: string | null
  /** The rule that read it; null: the generic reading. */
  rule: number | null
}

export interface SourceNotification {
  id: string
  title: string
  text: string
  postedAt: string
  status: 'draft' | 'ignored' | 'unread'
  /** The draft it became, as it stands now. */
  draft: { id: string; status: 'pending' | 'accepted' | 'matched' | 'discarded'; transactionId: string | null } | null
  reading: Reading | null
}

/** What a rule reads among all the source's notifications (each goes to the first rule that reads it). */
export interface RuleStat {
  matches: number
  lastMatch: string | null
}

/** How the rules read the latest notifications, and what each reads over time (one stat per rule). */
export interface SourceReading {
  notifications: SourceNotification[]
  ruleStats: RuleStat[]
}

export const sourcesApi = {
  async list(): Promise<NotificationSource[]> {
    return (await api.get<NotificationSource[]>('/api/sources')).data
  },

  async get(id: string): Promise<NotificationSource & SourceReading> {
    return (await api.get<NotificationSource & SourceReading>(`/api/sources/${id}`)).data
  },

  async update(id: string, accountId: string | null, rules: SourceRule[]): Promise<NotificationSource> {
    return (await api.put<NotificationSource>(`/api/sources/${id}`, { accountId, rules })).data
  },

  /** How these rules, not saved yet, read the latest notifications. */
  async preview(id: string, rules: SourceRule[]): Promise<SourceReading> {
    return (await api.post<SourceReading>(`/api/sources/${id}/preview`, { rules })).data
  },

  /** For good, with its notifications; its drafts still waiting are discarded. */
  async remove(id: string): Promise<void> {
    await api.delete(`/api/sources/${id}`)
  },

  async reread(id: string): Promise<number> {
    return (await api.post<{ drafts: number }>(`/api/sources/${id}/reread`)).data.drafts
  },
}
