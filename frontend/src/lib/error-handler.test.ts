import { describe, expect, it } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'
import i18n from '@/i18n/config'
import { handleApiError } from './error-handler'

function apiError(status: number, data: unknown): AxiosError {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError('failed', 'ERR', config, null, { status, statusText: '', headers: {}, config, data })
}

describe('handleApiError', () => {
  it('shows the translated code, never the backend text', async () => {
    await i18n.changeLanguage('en')
    expect(handleApiError(apiError(404, { code: 'transaction_not_found', message: 'Transaction not found.' }))).toBe(
      i18n.t('errors.api.transaction_not_found'),
    )
  })

  it('names the field of a validation error', async () => {
    await i18n.changeLanguage('it')
    const text = handleApiError(apiError(422, { violations: [{ propertyPath: 'amount', code: 'invalid_number' }] }))
    expect(text).toBe(`${i18n.t('errors.fields.amount')}: ${i18n.t('errors.validation.invalid_number')}`)
  })

  it('falls back to a text for the status', async () => {
    await i18n.changeLanguage('en')
    expect(handleApiError(apiError(500, { message: 'SQLSTATE[...]' }))).toBe(i18n.t('errors.serverError'))
  })
})
