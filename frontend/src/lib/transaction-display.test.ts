import { describe, expect, it } from 'vitest'
import { amountColorClass, amountCurrency, detailPath, signedAmount } from './transaction-display'
import type { Transaction } from '@/types/transaction'

type Side = NonNullable<Transaction['sourceAccount']>
const side = (id: string, type: Side['type']): Side => ({ id, name: id, type }) as Side

function row(source: Side | null, destination: Side | null, kind: Transaction['kind'] = 'money'): Transaction {
  return { id: 't1', amount: '100.0000', sourceAccount: source, destinationAccount: destination, kind } as Transaction
}

const checking = side('checking', 'asset')
const savings = side('savings', 'asset')
const shop = side('shop', 'expense')
const employer = side('employer', 'revenue')

describe('signedAmount', () => {
  it('shows money leaving as negative and money coming in as positive', () => {
    expect(signedAmount(row(checking, shop))).toBe(-100)
    expect(signedAmount(row(checking, null))).toBe(-100)
    expect(signedAmount(row(employer, checking))).toBe(100)
    expect(signedAmount(row(null, checking))).toBe(100)
    expect(signedAmount(row(checking, null, 'investmentBuy'))).toBe(-100)
    expect(signedAmount(row(null, checking, 'investmentSell'))).toBe(100)
  })

  it('shows a transfer from the account whose list it is', () => {
    const transfer = row(checking, savings)
    expect(signedAmount(transfer, 'checking')).toBe(-100)
    expect(signedAmount(transfer, 'savings')).toBe(100)
    expect(signedAmount(transfer)).toBe(100)
  })
})

describe('a transfer between currencies', () => {
  const crossing = { ...row(checking, savings), currency: 'EUR', destinationAmount: '110.0000', destinationCurrency: 'USD' } as Transaction

  it('leaves the source by what left and reaches the destination by what arrived, each in its own currency', () => {
    expect(signedAmount(crossing, 'checking')).toBe(-100)
    expect(amountCurrency(crossing, 'checking')).toBe('EUR')
    expect(signedAmount(crossing, 'savings')).toBe(110)
    expect(amountCurrency(crossing, 'savings')).toBe('USD')
  })

  it('is the amount that left in a list across accounts', () => {
    expect(signedAmount(crossing)).toBe(100)
    expect(amountCurrency(crossing)).toBe('EUR')
  })
})

describe('amountColorClass', () => {
  it('colours out, in and transfers', () => {
    expect(amountColorClass(row(checking, shop))).toBe('text-negative')
    expect(amountColorClass(row(employer, checking))).toBe('text-positive')
    expect(amountColorClass(row(checking, savings))).toBe('text-info')
    expect(amountColorClass(row(checking, null, 'investmentBuy'))).toBe('text-negative')
  })
})

describe('detailPath', () => {
  it('opens a trade on its own page', () => {
    expect(detailPath(row(checking, shop))).toBe('/transactions/t1')
    expect(detailPath(row(checking, null, 'investmentSell'))).toBe('/transactions/investments/t1')
  })
})
