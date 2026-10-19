import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import LanguageDetector from 'i18next-browser-languagedetector';

import en from './locales/en.json';
import it from './locales/it.json';

const resources = {
  en: { translation: en },
  it: { translation: it },
};

i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources,
    fallbackLng: 'en',
    // Browsers report a region ("it-IT" on iPad): narrowed to "it"/"en", so
    // `i18n.language` is always one of these (date formats compare against it).
    supportedLngs: ['it', 'en'],
    debug: false,
    interpolation: {
      escapeValue: false,
    },
    detection: {
      order: ['localStorage', 'navigator'],
      caches: ['localStorage'],
      lookupLocalStorage: 'i18nextLng',
    },
  });

// The page's language follows the interface's: screen readers and the
// browser's translate offer read it (index.html starts as "it").
// The installable app's name and shortcuts too (public/manifest*.webmanifest).
const syncDocumentLanguage = (language?: string) => {
  if (language && typeof document !== 'undefined') {
    document.documentElement.lang = language;
    document
      .querySelector('link[rel="manifest"]')
      ?.setAttribute('href', language === 'it' ? '/manifest.webmanifest' : '/manifest.en.webmanifest');
  }
};
syncDocumentLanguage(i18n.resolvedLanguage);
i18n.on('languageChanged', syncDocumentLanguage);

export default i18n;
