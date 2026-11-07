import { usePage } from '@inertiajs/vue3';

type Replacements = Record<string, string | number | null | undefined>;

let page: ReturnType<typeof usePage> | null = null;

/**
 * Translate an English source string with the translations shared by the server
 * (lang/{locale}.json). Placeholders use Laravel's :name syntax.
 */
export function t(key: string, replacements: Replacements = {}): string {
    page ??= usePage();
    const translations = (page.props.translations ?? {}) as Record<
        string,
        string
    >;
    let text = translations[key] ?? key;

    for (const [name, value] of Object.entries(replacements)) {
        text = text.replaceAll(`:${name}`, String(value ?? ''));
    }

    return text;
}

export function currentLocale(): string {
    page ??= usePage();

    return (page.props.locale as string | undefined) ?? 'nl';
}
