import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useWebPushContext } from '@/hooks/use-web-push-subscription';
import { logout } from '@/routes';

type SignOutDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function SignOutDialog({ open, onOpenChange }: SignOutDialogProps) {
    const { detachBeforeLogout } = useWebPushContext();
    const [signingOut, setSigningOut] = useState(false);

    const handleSignOut = async () => {
        if (signingOut) {
            return;
        }

        setSigningOut(true);

        try {
            await detachBeforeLogout();
        } finally {
            router.post(logout.url(), undefined, {
                onFinish: () => setSigningOut(false),
            });
        }
    };

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Sign out"
            desc="Are you sure you want to sign out? You will need to sign in again to access your account."
            confirmText={signingOut ? 'Signing out…' : 'Sign out'}
            destructive
            handleConfirm={() => {
                void handleSignOut();
            }}
            className="sm:max-w-sm"
        />
    );
}
