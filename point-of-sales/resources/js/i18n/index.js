import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import LanguageDetector from 'i18next-browser-languagedetector';

import id from './locales/id.json';
import en from './locales/en.json';
import th from './locales/th.json';

const resources = {
    id: { translation: id },
    en: { translation: en },
    th: { translation: th },
};

i18n
    .use(LanguageDetector)
    .use(initReactI18next)
    .init({
        resources,
        fallbackLng: ['th', 'en', 'id'],
        supportedLngs: ['th', 'id', 'en'],
        interpolation: {
            escapeValue: false,
        },
        detection: {
            order: ['localStorage', 'navigator'],
            caches: ['localStorage'],
        },
    });

export default i18n;

export const languages = [
    { code: 'th', name: 'Thai', nativeName: 'ไทย' },
    { code: 'id', name: 'Indonesia', nativeName: 'Indonesia' },
    { code: 'en', name: 'English', nativeName: 'English' },
];
