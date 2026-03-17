export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppShellVariant = 'header' | 'sidebar';
export type FlashToastType = 'success' | 'error' | 'info' | 'warning';

export type FlashToast = {
    id: string;
    type: FlashToastType;
    title: string;
    message?: string;
};
