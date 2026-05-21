/** Shared employee photo / initials logic — keep list cards and profile in sync. */

const AVATAR_GRADIENTS = [
    'from-primary to-accent',
    'from-sky-600 to-cyan-600',
    'from-emerald-600 to-teal-600',
    'from-amber-600 to-orange-600',
    'from-rose-600 to-pink-600',
    'from-fuchsia-600 to-purple-600',
] as const;

export function resolveEmployeeImageUrl(image?: string | null): string | null {
    if (!image) {
        return null;
    }

    return image.startsWith('http') ? image : `/storage/${image.replace(/^\/+/, '')}`;
}

export function getEmployeeInitials(name: string): string {
    return (
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase() || 'E'
    );
}

export function getEmployeeAvatarGradient(name: string): string {
    const hash = name.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);

    return AVATAR_GRADIENTS[hash % AVATAR_GRADIENTS.length];
}
