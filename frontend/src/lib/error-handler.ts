import { AxiosError } from 'axios';
import i18n from '../i18n/config';
import { formatMoney } from './utils';

/**
 * An error as the API sends it (backend `ExceptionListener`). `message` is
 * English and never shown: the text comes from `code` (`errors.api.*`) or from
 * each violation's code and field (`errors.validation.*`, `errors.fields.*`).
 */
interface ApiErrorBody {
  code?: string;
  params?: Record<string, string | number>;
  violations?: ApiViolation[];
}

interface ApiViolation {
  propertyPath: string;
  code: string;
  params?: Record<string, string | number>;
}

// Params that are translated or formatted before going into the text.
const accountSide = (value: string | number) => i18n.t(`errors.accountSide.${value}`);
const paramFormatters: Record<string, (value: string | number) => string> = {
  source: accountSide,
  destination: accountSide,
  amount: (value) => formatMoney(value),
  expected: (value) => formatMoney(value),
};

// Validation codes that describe a problem with one field ("Amount: must be
// greater than zero"); the others are whole sentences of their own.
const FIELD_CODES = new Set([
  'required', 'too_short', 'too_long', 'wrong_length', 'must_be_positive', 'must_not_be_negative',
  'out_of_range', 'invalid_email', 'invalid_choice', 'invalid_type', 'invalid',
]);

function formatParams(params: Record<string, string | number> = {}): Record<string, string | number> {
  const formatted: Record<string, string | number> = {};
  for (const [name, value] of Object.entries(params)) {
    formatted[name] = paramFormatters[name]?.(value) ?? value;
  }
  // Plural texts (e.g. "try again in 1 minute / 15 minutes").
  if (params.minutes !== undefined) {
    formatted.count = Number(params.minutes);
  }
  return formatted;
}

function translateCode(code: string): string | null {
  return i18n.exists(`errors.api.${code}`) || i18n.exists(`errors.api.${code}_other`) ? `errors.api.${code}` : null;
}

function translateViolation(violation: ApiViolation): string {
  const key = i18n.exists(`errors.validation.${violation.code}`) ? violation.code : 'invalid';
  const text = i18n.t(`errors.validation.${key}`, formatParams(violation.params));
  if (!FIELD_CODES.has(key)) {
    return text;
  }

  // "tagIds[0]" → "tagIds"
  const field = violation.propertyPath.replace(/\[\d+\]/g, '');
  if (field && i18n.exists(`errors.fields.${field}`)) {
    return `${i18n.t(`errors.fields.${field}`)}: ${text}`;
  }
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export const handleApiError = (error: unknown): string => {
  if (error instanceof AxiosError) {
    const status = error.response?.status;
    const data = error.response?.data as ApiErrorBody | undefined;

    if (data?.code) {
      const key = translateCode(data.code);
      if (key) {
        return i18n.t(key, formatParams(data.params));
      }
      if (import.meta.env.DEV) {
        console.warn(`No translation for API error code "${data.code}" (errors.api.${data.code})`);
      }
    }

    if (Array.isArray(data?.violations) && data.violations.length > 0) {
      // The first problem of each field only (an empty name is both "required"
      // and "too short"); each text ends with a full stop, so they read as one line.
      const firstPerField = data.violations.filter(
        (violation, index, all) => all.findIndex((other) => other.propertyPath === violation.propertyPath) === index,
      );
      return firstPerField.map(translateViolation).join(' ');
    }

    // No code: a text for the status, never the backend's English message.
    switch (status) {
      case 400:
      case 422:
        return i18n.t('errors.validationError');

      case 401:
        // Only login gets here (other 401s are refreshed or sent to /login by
        // the api.ts interceptor).
        return i18n.t('errors.invalidCredentials');

      case 403:
        return i18n.t('errors.unauthorized');

      case 404:
        return i18n.t('errors.notFound');

      case 409:
        return i18n.t('errors.conflict');

      case 429:
        return i18n.t('errors.tooManyRequests');

      case 500:
      case 502:
      case 503:
        return i18n.t('errors.serverError');

      default:
        if (error.message === 'Network Error') {
          return i18n.t('errors.networkError');
        }
        return i18n.t('errors.generic');
    }
  }

  return i18n.t('errors.generic');
};
