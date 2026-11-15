import { describe, expect, it } from 'vitest'
import { guessSettings, parse, toRows } from './csv'
import { isFireflyExport, readFirefly } from './firefly'
import { collectNames, itemsFromRows, pairTransferLegs } from './import-items'
import { accountKeys, commitBody, defaultAccountTarget, reviewRows, withChecks, type Lists, type Mapping } from './import-plan'

const FIREFLY = [
  'journal_id,type,currency_code,amount,foreign_currency_code,foreign_amount,description,date,source_name,source_type,destination_name,destination_type,category,tags,notes',
  '1,"Opening balance",EUR,100.00,,,"Saldo iniziale",2024-01-01T00:00:00+01:00,"Initial balance for Banca","Initial balance account",Banca,"Asset account",,,',
  '2,Deposit,EUR,1500.00,,,Stipendio,2024-01-10T08:00:00+01:00,Datore,"Revenue account",Banca,"Asset account",Stipendio,,',
  '3,Withdrawal,EUR,-20.00,,,Regalo,2024-01-11T12:00:00+01:00,Banca,"Asset account","Cash account","Cash account",Regali,,',
  '4,Withdrawal,EUR,-9.99,USD,-10.50,Spotify,2024-01-12T12:00:00+01:00,Banca,"Asset account",Spotify,"Expense account",Abbonamenti,"Musica,Casa","prima riga\nseconda"',
  '5,Transfer,EUR,200.00,,,Giroconto,2024-01-13T09:30:00+01:00,Banca,"Asset account",Carta,"Asset account",,,',
  '6,Reconciliation,EUR,-0.50,,,Riconciliazione,2024-01-31T00:00:00+01:00,Banca,"Asset account","Reconciliation account","Reconciliation account",,,',
  '7,Withdrawal,EUR,-5.00,,,Caffè,2024-01-14T08:00:00+01:00,Carta,"Asset account",Bar,"Expense account",,,',
].join('\n')

const LISTS: Lists = {
  assets: [{ id: 'carta-id', name: 'carta', currencyCode: 'EUR' }],
  expenses: [{ id: 'spotify-id', name: 'Spotify' }],
  revenues: [],
  categories: [{ id: 'abb-id', name: 'abbonamenti' }],
}

describe('Firefly export', () => {
  const table = parse(FIREFLY, ',')

  it('is recognised by its header', () => {
    expect(isFireflyExport(table[0])).toBe(true)
    expect(isFireflyExport(['Data', 'Descrizione', 'Importo'])).toBe(false)
  })

  it('becomes items on the side of the user account', () => {
    const { items, skipped } = readFirefly(table)
    expect(skipped).toEqual({})
    expect(items.map((item) => [item.kind, item.account, item.amount, item.counterparty, item.counterpartyHint])).toEqual([
      ['opening', 'Banca', '100.00', '', 'none'],
      ['transaction', 'Banca', '1500.00', 'Datore', 'payee'],
      ['transaction', 'Banca', '-20.00', 'Cash account', 'none'],
      ['transaction', 'Banca', '-9.99', 'Spotify', 'payee'],
      ['transaction', 'Banca', '-200.00', 'Carta', 'asset'],
      ['adjustment', 'Banca', '-0.50', '', 'none'],
      ['transaction', 'Carta', '-5.00', 'Bar', 'payee'],
    ])
    const spotify = items[3]
    expect([spotify.date, spotify.time, spotify.tags, spotify.notes, spotify.foreignAmount, spotify.foreignCurrency]).toEqual([
      '2024-01-12',
      '12:00:00',
      ['Musica', 'Casa'],
      'prima riga\nseconda',
      '10.50',
      'USD',
    ])
  })

  it('names its accounts, payees and categories once each', () => {
    const names = collectNames(readFirefly(table).items, false)
    expect(names.accounts.map((account) => [account.name, account.count, account.opening])).toEqual([
      ['Banca', 5, { amount: '100.00', date: '2024-01-01' }],
      ['Carta', 2, null],
    ])
    expect(names.payees.map((payee) => payee.name)).toEqual(['Bar', 'Datore', 'Spotify'])
    expect(names.categories.map((category) => category.name)).toEqual(['Abbonamenti', 'Regali', 'Stipendio'])
  })

  it('becomes the payload of one import', () => {
    const { items } = readFirefly(table)
    const names = collectNames(items, false)
    const keys = accountKeys(names.accounts)
    const mapping: Mapping = {
      fixedAccountId: null,
      accounts: Object.fromEntries(names.accounts.map((account) => [account.name.toLowerCase(), defaultAccountTarget(account, LISTS, 'EUR')])),
      payees: { datore: { kind: 'name', name: 'Azienda' } },
      categories: { regali: { kind: 'none' } },
    }
    expect(mapping.accounts).toEqual({ banca: { kind: 'new', name: 'Banca', currency: 'EUR' }, carta: { kind: 'existing', id: 'carta-id' } })

    const rows = reviewRows(items, mapping, LISTS, keys)
    const checked = withChecks(
      rows,
      rows.map((_, index) => ({
        index,
        duplicate: index === 4 ? { id: 'x', description: 'Caffè', occurredAt: '2024-01-14T08:00:00' } : null,
        recurring: null,
        // What was recorded before never fills a side or a category the file left out on purpose.
        suggestion: { account: { id: 'guess-id', name: 'Guess', type: 'expense' as const }, categoryId: 'guess-cat' },
      }))
    )
    const body = commitBody('firefly.csv', checked, items, names.accounts, mapping, keys, new Set(['carta-id']), 'Europe/Rome')

    expect(body.accounts).toEqual([{ key: 'a1', name: 'Banca', currencyCode: 'EUR', openingBalance: '100.00', openingBalanceDate: '2024-01-01' }])
    expect(body.adjustments).toEqual([{ accountKey: 'a1', date: '2024-01-31', amount: '-0.50', description: 'Riconciliazione' }])
    expect(body.rows).toHaveLength(4)
    const [salary, gift, spotify, transfer] = body.rows
    expect(salary).toMatchObject({ destinationAccountKey: 'a1', transaction: { newSourceAccountName: 'Azienda', newCategoryName: 'Stipendio', occurredAt: '2024-01-10T08:00:00' } })
    expect(gift).toMatchObject({ sourceAccountKey: 'a1', transaction: { destinationAccountId: null, newDestinationAccountName: null, categoryId: null, newCategoryName: null } })
    expect(spotify).toMatchObject({
      sourceAccountKey: 'a1',
      transaction: { amount: '9.99', destinationAccountId: 'spotify-id', categoryId: 'abb-id', newTagNames: ['Musica', 'Casa'], foreignAmount: '10.50', foreignCurrency: 'USD' },
    })
    expect(transfer).toMatchObject({ sourceAccountKey: 'a1', transaction: { destinationAccountId: 'carta-id', amount: '200.00', timezone: 'Europe/Rome' } })
  })
})

describe('a spreadsheet of several accounts', () => {
  const csv = [
    'Data;Conto;Descrizione;Beneficiario;Categoria;Importo',
    '10/01/2024;Banca;Giroconto;Carta;;-200,00',
    '10/01/2024;Carta;Giroconto;Banca;;200,00',
    '11/01/2024;Carta;Spesa;Esselunga;Spesa;-30,00',
  ].join('\n')

  it('reads the account column and keeps one leg of a transfer told twice', () => {
    const table = parse(csv, ';')
    const settings = guessSettings(table, 'utf-8', ';')
    expect([settings.accountMode, settings.accountColumn, settings.categoryColumn]).toEqual(['column', 1, 4])
    const items = itemsFromRows(toRows(table, settings))
    const own = new Set(['banca', 'carta'])
    const { items: left, paired } = pairTransferLegs(items, own)
    expect(paired).toBe(1)
    expect(left.map((item) => [item.account, item.amount, item.counterparty])).toEqual([
      ['Banca', '-200.00', 'Carta'],
      ['Carta', '-30.00', 'Esselunga'],
    ])
    expect(collectNames(left, false).payees.map((payee) => payee.name)).toEqual(['Esselunga'])
  })
})
